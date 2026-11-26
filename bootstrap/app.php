<?php

use App\Http\Middleware\AuthenticateFromCookie;
use App\Http\Middleware\EnsureUserIsActive;
use App\Http\Middleware\RenewSessionToken;
use App\Http\Middleware\SecurityHeaders;
use App\Http\Middleware\SetSchoolContext;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Os proxies de confiança (F-05) são configurados em config/trustedproxy.php
        // para que funcionem corretamente mesmo com `php artisan config:cache`.

        $middleware->append(SecurityHeaders::class);

        $middleware->api(prepend: [
            EncryptCookies::class,
            AddQueuedCookiesToResponse::class,
            AuthenticateFromCookie::class,
        ], append: [
            RenewSessionToken::class,
        ]);

        $middleware->alias([
            'active' => EnsureUserIsActive::class,
            'school.context' => SetSchoolContext::class,
        ]);

        $middleware->redirectGuestsTo(fn () => null);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // Dois pedidos em simultâneo podem passar a validação e só falhar na base de
        // dados (índice único). Para quem chama é um dado repetido, não um erro do servidor.
        $exceptions->render(function (UniqueConstraintViolationException $e, Request $request) {
            if ($request->is('api/*') || $request->expectsJson()) {
                return response()->json([
                    'message' => 'Já existe um registo com estes dados.',
                    'errors' => (object) [],
                ], 422);
            }
        });

        $exceptions->shouldRenderJsonWhen(function (Request $request, Throwable $e) {
            return $request->is('api/*') || $request->expectsJson();
        });
    })->create();
