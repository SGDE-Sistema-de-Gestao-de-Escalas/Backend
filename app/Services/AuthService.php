<?php

namespace App\Services;

use App\Models\Auth\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\Mail;
use App\Mail\ResetPasswordMail;
use App\Mail\SetupAccountMail;

class AuthService
{
    public const SESSION_MINUTES = 60 * 24;

    public const REMEMBER_MINUTES = 60 * 24 * 30;

    public function attemptLogin(array $credentials, int $minutes = self::SESSION_MINUTES): array
    {
        $user = User::with('role')->where('email', $credentials['email'])->first();

        $passwordMatches = $user 
            ? Hash::check($credentials['password'], $user->password)
            : Hash::check($credentials['password'], '$2y$12$e80y6mX1vL8rJ0dZbKvZ0eHj2b0D9yG0C1W9H2b0D9yG0C1W9H2b0');

        if (!$user || !$passwordMatches || !$user->is_active) {
            throw ValidationException::withMessages([
                'email' => ['As credenciais estão incorretas.'],
            ]);
        }

        $user->tokens()->delete();

        $token = $user->createToken('sgde-access-token', ['*'], now()->addMinutes($minutes))->plainTextToken;

        return [
            'user' => $user,
            'token' => $token,
        ];
    }

    public function updatePassword(User $user, string $newPassword): void
    {
        DB::transaction(function () use ($user, $newPassword) {
            $user->update([
                'password' => Hash::make($newPassword),
            ]);

            $user->tokens()->delete();
        });
    }

    public function logout(User $user): void
    {
        $accessToken = $user->currentAccessToken();

        if ($accessToken instanceof \Laravel\Sanctum\PersonalAccessToken) {
            $accessToken->delete();
        }
    }

    public function handleOAuthCallback(string $provider, \Laravel\Socialite\Contracts\User $socialUser, int $minutes = self::REMEMBER_MINUTES): string
    {
        $user = User::where('email', $socialUser->getEmail())->first();

        if (!$user) {
            throw ValidationException::withMessages([
                'email' => ['Utilizador não registado. Por favor, peça ao administrador para criar a sua conta primeiro.'],
            ]);
        }
        
        // Se o utilizador existe, verificamos se a sua conta está ativa
        if (!$user->is_active) {
            throw ValidationException::withMessages([
                'email' => ['Não foi possível iniciar sessão com esta conta.'],
            ]);
        }

        // Se o utilizador já tem um provider_id registado para outro provedor ou ID diferente, rejeita
        if ($user->provider_id && ($user->provider !== $provider || $user->provider_id !== $socialUser->getId())) {
            throw ValidationException::withMessages([
                'email' => ['Esta conta já se encontra associada a outro método de autenticação.'],
            ]);
        }

        // Se ainda não tem provider associado, associa agora
        if (!$user->provider_id) {
            $user->update([
                'provider' => $provider,
                'provider_id' => $socialUser->getId(),
            ]);
        }

        $user->tokens()->delete();
        
        return $user->createToken('sgde-access-token', ['*'], now()->addMinutes($minutes))->plainTextToken;
    }

    public function resetPasswordWithToken(array $data): void
    {
        $status = Password::broker()->reset(
            $data,
            function ($user, $password) {
                DB::transaction(function () use ($user, $password) {
                    $user->forceFill([
                        'password' => Hash::make($password)
                    ])->setRememberToken(\Illuminate\Support\Str::random(60));

                    $user->save();

                    $user->tokens()->delete();
                });
            }
        );

        if ($status !== Password::PASSWORD_RESET) {
            throw ValidationException::withMessages([
                'email' => [__($status)],
            ]);
        }
    }

    public function sendPasswordResetLink(string $email): void
    {
        $user = User::where('email', $email)->first();

        if ($user) {
            $token = Password::broker()->createToken($user);
            
            Mail::to($user->email)->send(new ResetPasswordMail($user, $token));
        }
    }

    public function sendAccountSetupLink(User $user): void
    {
        $user->loadMissing('role');
        $token = Password::broker()->createToken($user);

        Mail::to($user->email)->send(new SetupAccountMail($user, $token));
    }

    public function makeCookie(string $name, string $value, int $minutes, \Illuminate\Http\Request $request): \Symfony\Component\HttpFoundation\Cookie
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

    public function makeAuthCookie(string $token, int $minutes, \Illuminate\Http\Request $request): \Symfony\Component\HttpFoundation\Cookie
    {
        return $this->makeCookie('access_token', $token, $minutes, $request);
    }
}
