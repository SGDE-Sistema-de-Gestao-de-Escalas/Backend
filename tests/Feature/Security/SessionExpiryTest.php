<?php

namespace Tests\Feature\Security;

use App\Models\Auth\Role;
use App\Models\Auth\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Laravel\Socialite\Contracts\User as SocialUser;
use Laravel\Socialite\Facades\Socialite;
use Mockery;
use Tests\TestCase;

class SessionExpiryTest extends TestCase
{
    use RefreshDatabase;

    private const PASSWORD = 'Senha-Valida-2026';

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'app.frontend_url' => 'https://escalas.exemplo.pt',
            'services.google.client_id' => 'client-id',
            'services.google.client_secret' => 'client-secret',
            'services.google.redirect' => 'https://escalas.exemplo.pt/api/auth/google/callback',
        ]);

        $role = Role::firstOrCreate(['slug' => 'staff'], ['name' => 'Assistente Operacional']);
        User::factory()->create([
            'role_id' => $role->id,
            'email' => 'ana@escola.pt',
            'is_active' => true,
            'password' => Hash::make(self::PASSWORD),
        ]);
    }

    private function passwordLogin(bool $rememberMe): string
    {
        $response = $this->postJson('/api/login', [
            'email' => 'ana@escola.pt',
            'password' => self::PASSWORD,
            'remember_me' => $rememberMe,
        ])->assertOk();

        return $response->getCookie('access_token')->getValue();
    }

    private function googleLogin(): string
    {
        $socialUser = Mockery::mock(SocialUser::class);
        $socialUser->shouldReceive('getEmail')->andReturn('ana@escola.pt');
        $socialUser->shouldReceive('getId')->andReturn('google-123');

        $driver = Mockery::mock();
        $driver->shouldReceive('stateless')->andReturnSelf();
        $driver->shouldReceive('user')->andReturn($socialUser);

        Socialite::shouldReceive('driver')->with('google')->andReturn($driver);

        $response = $this->withCookie('oauth_state', 'estado-legitimo')
            ->get('/api/auth/google/callback?code=abc&state=estado-legitimo');

        return $response->getCookie('access_token')->getValue();
    }

    private function statusAt(string $token, $moment): int
    {
        $this->travelTo($moment);
        $this->app['auth']->forgetGuards();

        return $this->withToken($token)->getJson('/api/me')->getStatusCode();
    }

    public function test_the_global_token_expiration_does_not_override_the_expiry_of_each_token(): void
    {
        $this->assertNull(config('sanctum.expiration'));
    }

    public function test_a_normal_login_expires_after_4_hours_without_late_activity(): void
    {
        $start = now();
        $token = $this->passwordLogin(false);

        // Atividade antes da janela dos últimos 30 min (às 3h) não prolonga a sessão.
        $this->assertSame(200, $this->statusAt($token, $start->copy()->addHours(3)));
        $this->assertSame(401, $this->statusAt($token, $start->copy()->addHours(4)->addMinute()));
    }

    public function test_a_normal_login_renews_once_when_active_up_to_8_hours_max(): void
    {
        $start = now();
        $token = $this->passwordLogin(false);

        // Ativo nos últimos 30 min (às 3h35): renova 1x por +4h (até às 8h totais) e atualiza o cookie.
        $this->travelTo($start->copy()->addHours(3)->addMinutes(35));
        $this->app['auth']->forgetGuards();
        $renewResponse = $this->withCredentials()->withCookie('access_token', $token)->getJson('/api/me');
        $renewResponse->assertOk();
        $this->assertNotNull($renewResponse->getCookie('access_token'));

        // Continua válido após as 4h iniciais e até às 8h.
        $this->assertSame(200, $this->statusAt($token, $start->copy()->addHours(5)));
        $this->assertSame(200, $this->statusAt($token, $start->copy()->addHours(7)->addMinutes(45)));

        // Não renova uma segunda vez: expira obrigatoriamente às 8h totais.
        $this->assertSame(401, $this->statusAt($token, $start->copy()->addHours(8)->addMinute()));
    }

    public function test_session_durations_follow_sanctum_config(): void
    {
        config([
            'sanctum.session_minutes' => 60,
            'sanctum.session_max_minutes' => 120,
            'sanctum.session_renew_before_minutes' => 10,
            'sanctum.remember_minutes' => 180,
        ]);

        $start = now();
        $normalToken = $this->passwordLogin(false);
        $this->assertSame(200, $this->statusAt($normalToken, $start->copy()->addMinutes(55)));
        $this->assertSame(200, $this->statusAt($normalToken, $start->copy()->addMinutes(115)));
        $this->assertSame(401, $this->statusAt($normalToken, $start->copy()->addMinutes(125)));

        $this->travelBack();
        $start = now();
        $rememberToken = $this->passwordLogin(true);
        $this->assertSame(200, $this->statusAt($rememberToken, $start->copy()->addMinutes(170)));
        $this->assertSame(401, $this->statusAt($rememberToken, $start->copy()->addMinutes(185)));
    }

    public function test_a_remembered_login_expires_after_30_days(): void
    {
        $start = now();
        $token = $this->passwordLogin(true);

        $this->assertSame(200, $this->statusAt($token, $start->copy()->addDays(29)));
        $this->assertSame(401, $this->statusAt($token, $start->copy()->addDays(31)));
    }

    public function test_a_social_login_expires_after_30_days(): void
    {
        $start = now();
        $token = $this->googleLogin();

        $this->assertSame(200, $this->statusAt($token, $start->copy()->addDays(29)));
        $this->assertSame(401, $this->statusAt($token, $start->copy()->addDays(31)));
    }

    public function test_expired_tokens_are_pruned_every_day(): void
    {
        $this->artisan('schedule:list')
            ->expectsOutputToContain('sanctum:prune-expired')
            ->assertExitCode(0);
    }
}
