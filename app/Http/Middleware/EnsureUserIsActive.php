<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureUserIsActive
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        abort_unless($user, 401, 'Não autenticado.');

        abort_unless($user->is_active, 403, 'Conta desativada.');

        return $next($request);
    }
}