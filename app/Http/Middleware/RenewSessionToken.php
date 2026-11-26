<?php

namespace App\Http\Middleware;

use App\Models\Auth\PersonalAccessToken;
use App\Services\Auth\AuthService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class RenewSessionToken
{
    public function __construct(
        private readonly AuthService $authService,
    ) {}

    /**
     * Renova o token de sessão normal (sem "Lembrar-me") uma única vez quando o
     * utilizador continua ativo nos últimos minutos antes de expirar (por omissão
     * nos últimos 30 min), até ao limite máximo configurado (4h + 4h = 8h).
     */
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        $user = $request->user();
        if (! $user) {
            return $response;
        }

        $token = $user->currentAccessToken();
        if (! $token instanceof PersonalAccessToken) {
            return $response;
        }

        if ($token->name !== AuthService::TOKEN_NAME_SESSION || ! $token->expires_at || ! $token->created_at) {
            return $response;
        }

        $sessionMinutes = AuthService::sessionMinutes();
        $maxMinutes = AuthService::sessionMaxMinutes();

        if ($maxMinutes <= $sessionMinutes) {
            return $response;
        }

        $maxExpiresAt = $token->created_at->copy()->addMinutes($maxMinutes);

        if ($token->expires_at->greaterThanOrEqualTo($maxExpiresAt)) {
            return $response;
        }

        $renewBeforeMinutes = max(1, min(AuthService::sessionRenewBeforeMinutes(), $sessionMinutes));
        $renewThreshold = $token->expires_at->copy()->subMinutes($renewBeforeMinutes);

        if (now()->lessThan($renewThreshold)) {
            return $response;
        }

        $newExpiresAt = $token->expires_at->copy()->addMinutes($sessionMinutes);
        if ($newExpiresAt->greaterThan($maxExpiresAt)) {
            $newExpiresAt = $maxExpiresAt;
        }

        // Atualização atómica: ignora se o token foi revogado (ex.: logout/password)
        // ou se outro pedido paralelo já o renovou.
        $updated = PersonalAccessToken::query()
            ->whereKey($token->getKey())
            ->where('expires_at', '<', $maxExpiresAt)
            ->update(['expires_at' => $newExpiresAt]);

        if ($updated === 0) {
            return $response;
        }

        $token->expires_at = $newExpiresAt;

        if ($request->hasCookie('access_token')) {
            $remainingMinutes = max(1, (int) ceil(now()->diffInSeconds($newExpiresAt) / 60));

            $response->headers->setCookie(
                $this->authService->makeAuthCookie(
                    (string) $request->cookie('access_token'),
                    $remainingMinutes,
                    $request
                )
            );
        }

        return $response;
    }
}
