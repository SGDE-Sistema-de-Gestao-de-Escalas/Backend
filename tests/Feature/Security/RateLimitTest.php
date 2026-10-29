<?php

namespace Tests\Feature\Security;

use App\Models\Auth\Role;
use App\Models\Auth\User;
use App\Models\Schools\School;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * F-05 e F-06: limites de pedidos.
 *
 * - O login é limitado por email + IP (e, mais folgado, por IP), para que cinco
 *   colegas na mesma escola não se bloqueiem uns aos outros.
 * - A API autenticada tem um limite por utilizador, e as operações pesadas um
 *   limite mais apertado.
 * - Atrás de um proxy (Nginx) o IP verdadeiro vem em X-Forwarded-For, mas só
 *   se o proxy for de confiança: um cliente não pode inventar o cabeçalho.
 */
class RateLimitTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;
    private User $staff;
    private User $otherStaff;

    protected function setUp(): void
    {
        parent::setUp();

        $adminRole = Role::firstOrCreate(['slug' => 'admin'], ['name' => 'Administrador']);
        $staffRole = Role::firstOrCreate(['slug' => 'staff'], ['name' => 'Assistente Operacional']);

        $this->admin = User::factory()->create(['role_id' => $adminRole->id, 'is_active' => true]);
        $this->staff = User::factory()->create(['role_id' => $staffRole->id, 'is_active' => true]);
        $this->otherStaff = User::factory()->create(['role_id' => $staffRole->id, 'is_active' => true]);

        // Valores pequenos para os testes serem rápidos.
        config([
            'rate_limits.login.per_email' => 3,
            'rate_limits.login.per_ip' => 8,
            'rate_limits.password_forgot.per_email' => 2,
            'rate_limits.password_forgot.per_ip' => 5,
            'rate_limits.password_reset.per_ip' => 3,
            'rate_limits.api' => 6,
            'rate_limits.heavy' => 2,
        ]);
    }

    private function login(string $email, string $remote = '10.0.0.1', ?string $forwardedFor = null)
    {
        $request = $this->withServerVariables(['REMOTE_ADDR' => $remote]);

        if ($forwardedFor) {
            $request = $request->withHeader('X-Forwarded-For', $forwardedFor);
        }

        return $request->postJson('/api/login', ['email' => $email, 'password' => 'errada']);
    }

    // ------------------------------------------------------------------ F-05

    public function test_login_is_limited_per_email_and_ip(): void
    {
        foreach (range(1, 3) as $i) {
            $this->login('ana@escola.pt')->assertStatus(422);
        }

        $this->login('ana@escola.pt')->assertStatus(429);
    }

    public function test_colleagues_behind_the_same_ip_do_not_block_each_other(): void
    {
        // Três tentativas falhadas da Ana esgotam o limite dela...
        foreach (range(1, 3) as $i) {
            $this->login('ana@escola.pt');
        }
        $this->login('ana@escola.pt')->assertStatus(429);

        // ...mas o Rui, no mesmo computador/IP, continua a poder entrar.
        $this->login('rui@escola.pt')->assertStatus(422);
        $this->login('rui@escola.pt')->assertStatus(422);
    }

    public function test_the_email_is_compared_ignoring_case_and_spaces(): void
    {
        foreach (['ana@escola.pt', 'ANA@escola.pt', ' Ana@Escola.pt '] as $email) {
            $this->login($email);
        }

        $this->login('ana@escola.pt')->assertStatus(429);
    }

    public function test_one_ip_cannot_try_many_different_emails(): void
    {
        foreach (range(1, 8) as $i) {
            $this->login("pessoa{$i}@escola.pt")->assertStatus(422);
        }

        $this->login('pessoa9@escola.pt')->assertStatus(429);
    }

    public function test_the_limit_response_is_json_in_portuguese_with_retry_after(): void
    {
        foreach (range(1, 3) as $i) {
            $this->login('ana@escola.pt');
        }

        $response = $this->login('ana@escola.pt')->assertStatus(429);

        $response->assertJsonPath('message', 'Demasiados pedidos. Tente novamente dentro de alguns instantes.');
        $this->assertNotNull($response->headers->get('Retry-After'));
    }

    public function test_password_recovery_requests_are_limited_per_email(): void
    {
        $this->postJson('/api/password/forgot', ['email' => 'ana@escola.pt'])->assertOk();
        $this->postJson('/api/password/forgot', ['email' => 'ana@escola.pt'])->assertOk();
        $this->postJson('/api/password/forgot', ['email' => 'ana@escola.pt'])->assertStatus(429);

        // Outro email, mesmo IP, continua a poder pedir.
        $this->postJson('/api/password/forgot', ['email' => 'rui@escola.pt'])->assertOk();
    }

    public function test_password_reset_with_a_token_is_limited_per_ip(): void
    {
        $payload = ['token' => 'x', 'email' => 'ana@escola.pt', 'password' => 'nova-password-1', 'password_confirmation' => 'nova-password-1'];

        foreach (range(1, 3) as $i) {
            $this->postJson('/api/password/reset', $payload)->assertStatus(422);
        }

        $this->postJson('/api/password/reset', $payload)->assertStatus(429);
    }

    public function test_login_buckets_do_not_leak_into_password_recovery(): void
    {
        foreach (range(1, 3) as $i) {
            $this->login('ana@escola.pt');
        }
        $this->login('ana@escola.pt')->assertStatus(429);

        $this->postJson('/api/password/forgot', ['email' => 'ana@escola.pt'])->assertOk();
    }

    // ------------------------------------------------------- proxies (F-05)

    public function test_the_real_client_ip_is_used_when_the_proxy_is_trusted(): void
    {
        // O Nginx está em 127.0.0.1 e envia o IP real em X-Forwarded-For.
        foreach (range(1, 8) as $i) {
            $this->login("pessoa{$i}@escola.pt", '127.0.0.1', '203.0.113.7')->assertStatus(422);
        }
        $this->login('pessoa9@escola.pt', '127.0.0.1', '203.0.113.7')->assertStatus(429);

        // Outro cliente atrás do mesmo proxy tem o seu próprio limite.
        $this->login('pessoa10@escola.pt', '127.0.0.1', '203.0.113.8')->assertStatus(422);
    }

    public function test_a_client_cannot_dodge_the_limit_by_inventing_x_forwarded_for(): void
    {
        // Este pedido NÃO vem de um proxy de confiança: o cabeçalho é ignorado.
        foreach (range(1, 8) as $i) {
            $this->login("pessoa{$i}@escola.pt", '198.51.100.9', "1.2.3.{$i}")->assertStatus(422);
        }

        $this->login('pessoa9@escola.pt', '198.51.100.9', '1.2.3.99')->assertStatus(429);
    }

    // ------------------------------------------------------------------ F-06

    public function test_authenticated_api_requests_are_limited_per_user(): void
    {
        Sanctum::actingAs($this->staff);

        foreach (range(1, 6) as $i) {
            $this->getJson('/api/me')->assertOk();
        }

        $this->getJson('/api/me')->assertStatus(429);
    }

    public function test_one_users_traffic_does_not_limit_another_user(): void
    {
        Sanctum::actingAs($this->staff);
        foreach (range(1, 7) as $i) {
            $this->getJson('/api/me');
        }
        $this->getJson('/api/me')->assertStatus(429);

        Sanctum::actingAs($this->otherStaff);
        $this->getJson('/api/me')->assertOk();
    }

    public function test_expensive_operations_have_a_tighter_limit(): void
    {
        Sanctum::actingAs($this->admin);

        // O corpo é inválido de propósito (422): o que conta é o limite, não a geração.
        foreach (range(1, 2) as $i) {
            $this->postJson('/api/schedules/generate', [])->assertStatus(422);
        }

        $this->postJson('/api/schedules/generate', [])->assertStatus(429);

        // O limite apertado não afeta os restantes pedidos.
        $this->getJson('/api/me')->assertOk();
    }

    public function test_csv_export_has_the_tighter_limit(): void
    {
        Sanctum::actingAs($this->admin);
        $school = School::create(['name' => 'Escola A', 'acronym' => 'EA', 'active' => true]);

        $export = fn () => $this->withHeader('X-School-ID', $school->id)->get('/api/assistants/export?status=all');

        $export()->assertOk();
        $export()->assertOk();
        $export()->assertStatus(429);
    }
}
