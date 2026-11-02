<?php

namespace Tests\Feature\Security;

use App\Models\Auth\Role;
use App\Models\Auth\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * F-15: faltavam os cabeçalhos de segurança nas respostas da API.
 */
class SecurityHeadersTest extends TestCase
{
    use RefreshDatabase;

    public static function responses(): array
    {
        return [
            'resposta 200' => ['/api/teste', 200],
            'rota que não existe' => ['/api/nao-existe', 404],
            'sem sessão' => ['/api/me', 401],
        ];
    }

    #[DataProvider('responses')]
    public function test_every_response_carries_the_security_headers(string $uri, int $status): void
    {
        $response = $this->getJson($uri);

        $response->assertStatus($status);
        $response->assertHeader('X-Content-Type-Options', 'nosniff');
        $response->assertHeader('X-Frame-Options', 'DENY');
        $response->assertHeader('Referrer-Policy', 'no-referrer');
        $response->assertHeader('Content-Security-Policy', "default-src 'none'; frame-ancestors 'none'");
        $response->assertHeader('Permissions-Policy');
    }

    public function test_a_validation_error_carries_the_headers_too(): void
    {
        $role = Role::firstOrCreate(['slug' => 'admin'], ['name' => 'Administrador']);
        Sanctum::actingAs(User::factory()->create(['role_id' => $role->id, 'is_active' => true]));

        $this->postJson('/api/schedules/generate', [])
            ->assertStatus(422)
            ->assertHeader('X-Content-Type-Options', 'nosniff')
            ->assertHeader('X-Frame-Options', 'DENY');
    }

    public function test_hsts_is_only_sent_over_https(): void
    {
        $this->getJson('http://localhost/api/teste')->assertHeaderMissing('Strict-Transport-Security');

        $this->getJson('https://localhost/api/teste')
            ->assertHeader('Strict-Transport-Security', 'max-age=31536000');
    }

    public function test_the_response_never_announces_the_php_version(): void
    {
        $this->getJson('/api/teste')->assertHeaderMissing('X-Powered-By');
    }
}
