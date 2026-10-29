<?php

namespace Tests\Feature\Security;

use App\Models\Auth\Role;
use App\Models\Auth\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * F-08: mudar o email da PRÓPRIA conta exige a password atual.
 *
 * O email é o que recebe a recuperação de password. Se uma sessão roubada (ou
 * um computador partilhado deixado aberto) pudesse trocá-lo sem password,
 * bastava pedir uma recuperação para ficar com a conta.
 */
class EmailChangeTest extends TestCase
{
    use RefreshDatabase;

    private const PASSWORD = 'Password-atual-123';

    private User $admin;
    private User $staff;

    protected function setUp(): void
    {
        parent::setUp();

        $adminRole = Role::firstOrCreate(['slug' => 'admin'], ['name' => 'Administrador']);
        $staffRole = Role::firstOrCreate(['slug' => 'staff'], ['name' => 'Assistente Operacional']);

        $this->admin = User::factory()->create([
            'role_id' => $adminRole->id,
            'email' => 'admin@escola.pt',
            'password' => Hash::make(self::PASSWORD),
            'is_active' => true,
        ]);

        $this->staff = User::factory()->create([
            'role_id' => $staffRole->id,
            'first_name' => 'Ana',
            'last_name' => 'Silva',
            'email' => 'ana@escola.pt',
            'password' => Hash::make(self::PASSWORD),
            'is_active' => true,
        ]);
    }

    private function profile(array $overrides = []): array
    {
        return array_merge([
            'first_name' => 'Ana',
            'last_name' => 'Silva',
            'email' => 'ana@escola.pt',
        ], $overrides);
    }

    public function test_changing_own_email_without_the_password_is_rejected(): void
    {
        Sanctum::actingAs($this->staff);

        $this->patchJson('/api/me', ['email' => 'novo@escola.pt'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('current_password');

        $this->assertSame('ana@escola.pt', $this->staff->fresh()->email);
    }

    public function test_changing_own_email_with_a_wrong_password_is_rejected(): void
    {
        Sanctum::actingAs($this->staff);

        $this->putJson('/api/me', $this->profile(['email' => 'novo@escola.pt', 'current_password' => 'errada']))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('current_password');

        $this->assertSame('ana@escola.pt', $this->staff->fresh()->email);
    }

    public function test_changing_own_email_with_the_right_password_works(): void
    {
        Sanctum::actingAs($this->staff);

        $this->putJson('/api/me', $this->profile(['email' => 'novo@escola.pt', 'current_password' => self::PASSWORD]))
            ->assertOk();

        $this->assertSame('novo@escola.pt', $this->staff->fresh()->email);
    }

    public function test_the_password_is_not_saved_anywhere_when_changing_the_email(): void
    {
        Sanctum::actingAs($this->staff);

        $before = $this->staff->fresh()->password;

        $this->putJson('/api/me', $this->profile(['email' => 'novo@escola.pt', 'current_password' => self::PASSWORD]))
            ->assertOk()
            ->assertJsonMissingPath('data.current_password');

        $this->assertSame($before, $this->staff->fresh()->password);
    }

    public function test_the_same_email_in_a_different_case_does_not_need_the_password(): void
    {
        Sanctum::actingAs($this->staff);

        $this->putJson('/api/me', $this->profile(['email' => 'ANA@escola.pt', 'first_name' => 'Ana Maria']))
            ->assertOk();

        $this->assertSame('Ana Maria', $this->staff->fresh()->first_name);
    }

    public function test_editing_only_the_name_with_the_unchanged_email_does_not_need_the_password(): void
    {
        Sanctum::actingAs($this->staff);

        // É o que o formulário do perfil envia: o email vem sempre, mesmo sem mudar.
        $this->putJson('/api/me', $this->profile(['last_name' => 'Santos']))->assertOk();
        $this->patchJson('/api/me', ['first_name' => 'Joana'])->assertOk();

        $fresh = $this->staff->fresh();
        $this->assertSame('Joana', $fresh->first_name);
        $this->assertSame('Santos', $fresh->last_name);
        $this->assertSame('ana@escola.pt', $fresh->email);
    }

    public function test_changing_own_email_through_the_users_route_also_needs_the_password(): void
    {
        Sanctum::actingAs($this->staff);

        $this->putJson("/api/users/{$this->staff->id}", $this->profile(['email' => 'novo@escola.pt']))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('current_password');

        $this->assertSame('ana@escola.pt', $this->staff->fresh()->email);
    }

    public function test_an_admin_changing_their_own_email_also_needs_the_password(): void
    {
        Sanctum::actingAs($this->admin);

        $this->patchJson('/api/me', ['email' => 'admin-novo@escola.pt'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('current_password');
    }

    public function test_the_email_of_another_account_is_still_rejected_as_taken(): void
    {
        Sanctum::actingAs($this->staff);

        $this->putJson('/api/me', $this->profile(['email' => 'admin@escola.pt', 'current_password' => self::PASSWORD]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('email');

        $this->assertSame('ana@escola.pt', $this->staff->fresh()->email);
    }

    public function test_an_admin_can_still_change_another_users_email_without_a_password(): void
    {
        Sanctum::actingAs($this->admin);

        $this->putJson("/api/users/{$this->staff->id}", $this->profile(['email' => 'ana.nova@escola.pt']))
            ->assertOk();

        $this->assertSame('ana.nova@escola.pt', $this->staff->fresh()->email);
    }
}
