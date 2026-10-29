<?php

namespace Tests\Feature\Security;

use App\Models\Auth\Role;
use App\Models\Auth\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Socialite\Contracts\User as SocialUser;
use Laravel\Socialite\Facades\Socialite;
use Mockery;
use Tests\TestCase;

/**
 * F-10: o login com Google/Microsoft tem de usar o parâmetro `state`
 * (protege contra "login CSRF": alguém forçar a sessão da vítima a entrar
 * na conta do atacante). O `state` é gerado em /redirect, guardado num
 * cookie HttpOnly e só aceite uma vez em /callback.
 */
class OAuthStateTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

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
        $this->user = User::factory()->create([
            'role_id' => $role->id,
            'email' => 'ana@escola.pt',
            'is_active' => true,
        ]);
    }

    private function mockGoogleUser(string $email = 'ana@escola.pt', string $id = 'google-123'): void
    {
        $socialUser = Mockery::mock(SocialUser::class);
        $socialUser->shouldReceive('getEmail')->andReturn($email);
        $socialUser->shouldReceive('getId')->andReturn($id);

        $driver = Mockery::mock();
        $driver->shouldReceive('stateless')->andReturnSelf();
        $driver->shouldReceive('user')->andReturn($socialUser);

        Socialite::shouldReceive('driver')->with('google')->andReturn($driver);
    }

    private function assertRedirectedWithError($response): void
    {
        $response->assertRedirect();
        $location = $response->headers->get('Location');

        $this->assertStringStartsWith('https://escalas.exemplo.pt/auth/callback?error=', $location);
        $this->assertNull($response->getCookie('access_token', false), 'Não pode haver sessão sem state válido.');
    }

    public function test_redirect_sends_a_state_that_matches_a_http_only_cookie(): void
    {
        $response = $this->getJson('/api/auth/google/redirect')->assertOk();

        parse_str(parse_url($response->json('url'), PHP_URL_QUERY), $query);
        $this->assertNotEmpty($query['state'] ?? null, 'O URL do fornecedor tem de levar o parâmetro state.');
        $this->assertGreaterThanOrEqual(32, strlen($query['state']));

        $cookie = $response->getCookie('oauth_state', false);
        $this->assertNotNull($cookie, 'O state tem de ficar guardado num cookie.');
        $this->assertTrue($cookie->isHttpOnly());
        $this->assertSame($query['state'], $response->getCookie('oauth_state')->getValue());
    }

    public function test_each_redirect_gets_a_different_state(): void
    {
        $first = $this->getJson('/api/auth/google/redirect')->json('url');
        $second = $this->getJson('/api/auth/google/redirect')->json('url');

        parse_str(parse_url($first, PHP_URL_QUERY), $a);
        parse_str(parse_url($second, PHP_URL_QUERY), $b);

        $this->assertNotSame($a['state'], $b['state']);
    }

    public function test_callback_without_the_state_cookie_is_rejected(): void
    {
        Socialite::shouldReceive('driver')->never();

        $this->assertRedirectedWithError(
            $this->get('/api/auth/google/callback?code=abc&state=qualquer-coisa')
        );
    }

    public function test_callback_with_a_different_state_is_rejected(): void
    {
        Socialite::shouldReceive('driver')->never();

        $this->assertRedirectedWithError(
            $this->withCookie('oauth_state', 'estado-legitimo')
                ->get('/api/auth/google/callback?code=abc&state=estado-do-atacante')
        );
    }

    public function test_callback_without_a_state_parameter_is_rejected(): void
    {
        Socialite::shouldReceive('driver')->never();

        $this->assertRedirectedWithError(
            $this->withCookie('oauth_state', 'estado-legitimo')
                ->get('/api/auth/google/callback?code=abc')
        );
    }

    public function test_callback_with_a_matching_state_logs_the_user_in_and_burns_the_state(): void
    {
        $this->mockGoogleUser();

        $response = $this->withCookie('oauth_state', 'estado-legitimo')
            ->get('/api/auth/google/callback?code=abc&state=estado-legitimo');

        $response->assertRedirect('https://escalas.exemplo.pt/auth/callback');

        $access = $response->getCookie('access_token', false);
        $this->assertNotNull($access);
        $this->assertTrue($access->isHttpOnly());

        // O state só vale uma vez: o cookie é apagado na resposta.
        $state = $response->getCookie('oauth_state', false);
        $this->assertNotNull($state);
        $this->assertTrue($state->isCleared());
    }

    public function test_the_session_never_travels_in_the_redirect_url(): void
    {
        $this->mockGoogleUser();

        $response = $this->withCookie('oauth_state', 'estado-legitimo')
            ->get('/api/auth/google/callback?code=abc&state=estado-legitimo');

        $location = $response->headers->get('Location');
        $this->assertStringNotContainsString('token', $location);
        $this->assertStringNotContainsString('?', $location);
    }
}
