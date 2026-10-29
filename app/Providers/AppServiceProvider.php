<?php

namespace App\Providers;
use App\Models\Auth\User;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Support\ServiceProvider;
use App\Models\Auth\PersonalAccessToken;
use Laravel\Sanctum\Sanctum;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {   
        Sanctum::usePersonalAccessTokenModel(PersonalAccessToken::class);

        $this->configureRateLimiting();
        
        Gate::define(
            'manage-system', 
            fn (User $user): bool => $user->isAdmin()
        );
        

        \Illuminate\Support\Facades\Event::listen(function (\SocialiteProviders\Manager\SocialiteWasCalled $event) {
            $event->extendSocialite('azure', \SocialiteProviders\Azure\Provider::class);
        });

        \Illuminate\Auth\Notifications\ResetPassword::createUrlUsing(function (User $user, string $token) {
            return config('app.frontend_url') . '/reset-password?token=' . $token . '&email=' . urlencode($user->email);
        });

        \Illuminate\Support\Facades\Event::listen(function (\Illuminate\Mail\Events\MessageSending $event) {
            $iconPath = public_path('images/icon.png');
            if (is_file($iconPath) && str_contains((string) $event->message->getHtmlBody(), 'cid:sgde-logo')) {
                $event->message->embedFromPath($iconPath, 'sgde-logo', 'image/png');
            }
        });
    }

    /**
     * Limites de pedidos (F-05 e F-06). Os valores estão em config/rate_limits.php.
     */
    private function configureRateLimiting(): void
    {
        $tooMany = fn (Request $request, array $headers) => response()->json([
            'message' => 'Demasiados pedidos. Tente novamente dentro de alguns instantes.',
        ], 429, $headers);

        $email = fn (Request $request): string => Str::lower(trim((string) $request->input('email')));

        // Login: por email + IP (colegas na mesma escola não se bloqueiam) e por IP.
        RateLimiter::for('login', fn (Request $request) => [
            Limit::perMinute(config('rate_limits.login.per_email'))
                ->by('login:'.$email($request).'|'.$request->ip())
                ->response($tooMany),
            Limit::perMinute(config('rate_limits.login.per_ip'))
                ->by('login-ip:'.$request->ip())
                ->response($tooMany),
        ]);

        RateLimiter::for('password-forgot', fn (Request $request) => [
            Limit::perMinute(config('rate_limits.password_forgot.per_email'))
                ->by('forgot:'.$email($request).'|'.$request->ip())
                ->response($tooMany),
            Limit::perMinute(config('rate_limits.password_forgot.per_ip'))
                ->by('forgot-ip:'.$request->ip())
                ->response($tooMany),
        ]);

        RateLimiter::for('password-reset', fn (Request $request) => Limit::perMinute(config('rate_limits.password_reset.per_ip'))
            ->by('reset-ip:'.$request->ip())
            ->response($tooMany));

        RateLimiter::for('oauth', fn (Request $request) => Limit::perMinute(config('rate_limits.oauth'))
            ->by('oauth-ip:'.$request->ip())
            ->response($tooMany));

        // API autenticada: por utilizador (aplicado depois do auth:sanctum).
        RateLimiter::for('api', fn (Request $request) => Limit::perMinute(config('rate_limits.api'))
            ->by($request->user() ? 'user:'.$request->user()->getAuthIdentifier() : 'ip:'.$request->ip())
            ->response($tooMany));

        // Operações pesadas: limite próprio por utilizador, além do geral.
        RateLimiter::for('heavy', fn (Request $request) => Limit::perMinute(config('rate_limits.heavy'))
            ->by('heavy:'.($request->user()?->getAuthIdentifier() ?? $request->ip()))
            ->response($tooMany));
    }
}
