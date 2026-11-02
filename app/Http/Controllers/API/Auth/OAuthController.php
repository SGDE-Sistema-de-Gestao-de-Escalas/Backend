<?php

namespace App\Http\Controllers\API\Auth;

use App\Http\Controllers\Controller;
use App\Services\AuthService;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Laravel\Socialite\Facades\Socialite;
use Symfony\Component\HttpFoundation\Cookie as SymfonyCookie;

class OAuthController extends Controller
{
    /**
     * Cookie com o `state` do login social (F-10). É gerado em /redirect e só
     * é aceite uma vez em /callback: impede que alguém force a sessão de outra
     * pessoa a entrar na conta do atacante ("login CSRF").
     */
    private const STATE_COOKIE = 'oauth_state';

    private const STATE_MINUTES = 10;

    public function __construct(private readonly AuthService $authService) {}

    public function redirect(string $provider, Request $request)
    {
        if (!in_array($provider, ['google', 'azure'])) {
            return response()->json(['message' => 'Provider não suportado.'], 400);
        }

        $state = Str::random(40);

        $url = Socialite::driver($provider)
            ->stateless()
            ->with(['state' => $state])
            ->redirect()
            ->getTargetUrl();

        return response()->json(['url' => $url])
            ->withCookie($this->makeCookie(self::STATE_COOKIE, $state, self::STATE_MINUTES, $request));
    }

    public function externalLoginCallback(string $provider, Request $request)
    {
        if (!in_array($provider, ['google', 'azure'])) {
            return response()->json(['message' => 'Provider não suportado.'], 400);
        }

        if (! $this->hasValidState($request)) {
            return $this->redirectWithError('A sessão de autenticação expirou ou é inválida. Por favor tente novamente.');
        }

        try {
            $socialUser = Socialite::driver($provider)->stateless()->user();

            $token = $this->authService->handleOAuthCallback($provider, $socialUser);

            $cookie = $this->makeCookie(
                'access_token',
                $token,
                60 * 24 * 30, // 30 dias
                $request
            );

            // A sessão vai só no cookie HttpOnly: nunca no endereço de redirecionamento.
            return redirect()->away(config('app.frontend_url') . '/auth/callback')
                ->withCookie($cookie)
                ->withoutCookie(self::STATE_COOKIE);

        } catch (\Illuminate\Validation\ValidationException $e) {
            return $this->redirectWithError($e->validator->errors()->first() ?: 'Falha na validação do utilizador.');
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::error('Erro na autenticação OAuth (' . $provider . '): ' . $e->getMessage(), [
                'exception' => $e
            ]);

            return $this->redirectWithError('Ocorreu um erro ao processar a autenticação externa. Por favor tente novamente.');
        }
    }

    /**
     * O `state` devolvido pelo fornecedor tem de ser igual ao guardado no cookie.
     */
    private function hasValidState(Request $request): bool
    {
        $expected = (string) $request->cookie(self::STATE_COOKIE, '');
        $received = (string) $request->query('state', '');

        return $expected !== '' && $received !== '' && hash_equals($expected, $received);
    }

    private function redirectWithError(string $message)
    {
        return redirect()
            ->away(config('app.frontend_url') . '/auth/callback?error=' . urlencode($message))
            ->withoutCookie(self::STATE_COOKIE);
    }

    private function makeCookie(string $name, string $value, int $minutes, Request $request): SymfonyCookie
    {
        return cookie(
            name: $name,
            value: $value,
            minutes: $minutes,
            path: '/',
            domain: null,
            secure: config('app.env') === 'production' || $request->isSecure(),
            httpOnly: true,
            raw: false,
            sameSite: 'Lax'
        );
    }
}
