<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use App\Http\Middleware\EnsureUserIsActive;
use App\Http\Middleware\SetSchoolContext;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Atrás de um proxy (Nginx) o IP real vem em X-Forwarded-For. Só se
        // confia no cabeçalho se o pedido vier de um proxy desta lista, senão
        // qualquer cliente podia inventar o IP e fugir aos limites de pedidos.
        // TRUSTED_PROXIES: IPs separados por vírgulas, ou "*" (só se a aplicação
        // não for acessível diretamente).
        $trusted = trim((string) env('TRUSTED_PROXIES', '127.0.0.1'));
        $middleware->trustProxies(at: $trusted === '*'
            ? '*'
            : array_values(array_filter(array_map('trim', explode(',', $trusted)))));

        $middleware->api(prepend: [
            \Illuminate\Cookie\Middleware\EncryptCookies::class,
            \Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse::class,
            \App\Http\Middleware\AuthenticateFromCookie::class,
        ]);

        $middleware->alias([
            'active' => EnsureUserIsActive::class,
            'school.context' => SetSchoolContext::class,
        ]);

        $middleware->redirectGuestsTo(fn () => null);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(function (\Illuminate\Http\Request $request, Throwable $e) {
            return $request->is('api/*') || $request->expectsJson();
        });
    })->create();
