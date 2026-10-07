<?php

namespace App\Providers;
use App\Models\Auth\User;
use Illuminate\Support\Facades\Gate;
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
        
        Gate::define(
            'manage-system', 
            fn (User $user): bool => $user->isAdmin()
        );
        

        \Illuminate\Support\Facades\Event::listen(function (\SocialiteProviders\Manager\SocialiteWasCalled $event) {
            $event->extendSocialite('azure', \SocialiteProviders\Azure\Provider::class);
        });

        \Illuminate\Auth\Notifications\ResetPassword::createUrlUsing(function (User $user, string $token) {
            return env('FRONTEND_URL', 'http://localhost:3000') . '/reset-password?token=' . $token . '&email=' . urlencode($user->email);
        });
    }
}
