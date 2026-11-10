<?php

namespace Tests\Feature\Transactions;

use App\Models\Auth\Role;
use App\Models\Auth\User;
use App\Services\Auth\AuthService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use RuntimeException;
use Tests\TestCase;

class PasswordChangeTransactionTest extends TestCase
{
    use RefreshDatabase;

    private User $user;
    private string $oldHash;

    protected function setUp(): void
    {
        parent::setUp();

        $staffRole = Role::firstOrCreate(['slug' => 'staff'], ['name' => 'Assistente Operacional']);

        $this->user = User::factory()->create([
            'role_id' => $staffRole->id,
            'is_active' => true,
            'password' => Hash::make('PasswordAntiga123!'),
        ]);
        $this->user->createToken('sessao-aberta');
        $this->oldHash = $this->user->fresh()->password;
    }

    private function failWhenTokensAreRevoked(): void
    {
        DB::listen(function ($query) {
            $sql = strtolower(trim($query->sql));

            if (str_starts_with($sql, 'delete') && str_contains($sql, 'personal_access_tokens')) {
                throw new RuntimeException('falha ao revogar sessoes');
            }
        });
    }

    public function test_changing_the_password_also_revokes_the_sessions(): void
    {
        app(AuthService::class)->updatePassword($this->user, 'NovaPassword123!');

        $this->assertTrue(Hash::check('NovaPassword123!', $this->user->fresh()->password));
        $this->assertSame(0, $this->user->tokens()->count());
    }

    public function test_the_password_is_not_changed_when_the_sessions_cannot_be_revoked(): void
    {
        $this->failWhenTokensAreRevoked();

        try {
            app(AuthService::class)->updatePassword($this->user, 'NovaPassword123!');
            $this->fail('A mudanca devia ter falhado.');
        } catch (RuntimeException) {
        }

        $this->assertSame($this->oldHash, $this->user->fresh()->password);
        $this->assertSame(1, $this->user->tokens()->count());
    }

    public function test_resetting_with_a_token_also_revokes_the_sessions(): void
    {
        $token = Password::broker()->createToken($this->user);

        app(AuthService::class)->resetPasswordWithToken([
            'email' => $this->user->email,
            'token' => $token,
            'password' => 'NovaPassword123!',
            'password_confirmation' => 'NovaPassword123!',
        ]);

        $this->assertTrue(Hash::check('NovaPassword123!', $this->user->fresh()->password));
        $this->assertSame(0, $this->user->tokens()->count());
    }

    public function test_the_reset_does_not_change_the_password_when_the_sessions_cannot_be_revoked(): void
    {
        $token = Password::broker()->createToken($this->user);
        $this->failWhenTokensAreRevoked();

        try {
            app(AuthService::class)->resetPasswordWithToken([
                'email' => $this->user->email,
                'token' => $token,
                'password' => 'NovaPassword123!',
                'password_confirmation' => 'NovaPassword123!',
            ]);
            $this->fail('A reposicao devia ter falhado.');
        } catch (RuntimeException) {
        }

        $this->assertSame($this->oldHash, $this->user->fresh()->password);
        $this->assertSame(1, $this->user->tokens()->count());
    }
}
