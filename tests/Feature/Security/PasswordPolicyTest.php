<?php

namespace Tests\Feature\Security;

use App\Models\Auth\Role;
use App\Models\Auth\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class PasswordPolicyTest extends TestCase
{
    use RefreshDatabase;

    private const CURRENT = 'Senha-Antiga-2026';

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $role = Role::firstOrCreate(['slug' => 'staff'], ['name' => 'Assistente Operacional']);

        $this->user = User::factory()->create([
            'role_id' => $role->id,
            'email' => 'maria.santos@escola.pt',
            'is_active' => true,
            'password' => Hash::make(self::CURRENT),
        ]);
    }

    public static function weakPasswords(): array
    {
        return [
            'so numeros' => ['12345678'],
            'palavra comum' => ['password'],
            'repetida' => ['aaaaaaaa'],
            'curta com tudo' => ['Abc12345'],
            'sem maiuscula' => ['abcdefghij1'],
            'sem minuscula' => ['ABCDEFGHIJ1'],
            'sem numero' => ['Abcdefghijk'],
            'comum com enfeites' => ['Password123!'],
            'comum de teclado' => ['Qwerty12345'],
            'com o email' => ['Maria.Santos2026'],
        ];
    }

    private function change(string $password)
    {
        Sanctum::actingAs($this->user);

        return $this->postJson('/api/update-password', [
            'current_password' => self::CURRENT,
            'new_password' => $password,
            'new_password_confirmation' => $password,
        ]);
    }

    private function reset(string $password)
    {
        return $this->postJson('/api/password/reset', [
            'token' => Password::broker()->createToken($this->user),
            'email' => $this->user->email,
            'password' => $password,
            'password_confirmation' => $password,
        ]);
    }

    #[DataProvider('weakPasswords')]
    public function test_a_weak_password_is_refused_when_changing_it(string $password): void
    {
        $this->change($password)
            ->assertStatus(422)
            ->assertJsonValidationErrors(['new_password']);

        $this->assertTrue(Hash::check(self::CURRENT, $this->user->fresh()->password));
    }

    #[DataProvider('weakPasswords')]
    public function test_a_weak_password_is_refused_when_resetting_it(string $password): void
    {
        $this->reset($password)
            ->assertStatus(422)
            ->assertJsonValidationErrors(['password']);

        $this->assertTrue(Hash::check(self::CURRENT, $this->user->fresh()->password));
    }

    public function test_a_strong_password_is_accepted_when_changing_it(): void
    {
        $this->change('Cadeira-Azul-47x')->assertOk();

        $this->assertTrue(Hash::check('Cadeira-Azul-47x', $this->user->fresh()->password));
    }

    public function test_a_strong_password_is_accepted_when_resetting_it(): void
    {
        $this->reset('Cadeira-Azul-47x')->assertOk();

        $this->assertTrue(Hash::check('Cadeira-Azul-47x', $this->user->fresh()->password));
    }

    public function test_the_error_message_is_in_portuguese_and_explains_the_rule(): void
    {
        $message = $this->change('12345678')->json('errors.new_password.0');

        $this->assertStringContainsString('10 caracteres', $message);
    }
}
