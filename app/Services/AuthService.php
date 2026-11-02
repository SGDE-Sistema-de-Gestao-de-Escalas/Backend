<?php

namespace App\Services;

use App\Models\Auth\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\Mail;
use App\Mail\ResetPasswordMail;

class AuthService
{
    public function attemptLogin(array $credentials): array
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

        $token = $user->createToken('sgde-access-token')->plainTextToken;

        return [
            'user' => $user,
            'token' => $token,
        ];
    }

    public function updatePassword(User $user, string $newPassword): void
    {
        $user->update([
            'password' => Hash::make($newPassword),
        ]);

        $user->tokens()->delete();
    }

    public function logout(User $user): void
    {
        $accessToken = $user->currentAccessToken();

        if ($accessToken instanceof \Laravel\Sanctum\PersonalAccessToken) {
            $accessToken->delete();
        }
    }

    public function handleOAuthCallback(string $provider, \Laravel\Socialite\Contracts\User $socialUser): string
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
        
        return $user->createToken('sgde-access-token')->plainTextToken;
    }

    public function createUserAndSendResetLink(array $data): User
    {
        $data['password'] = \Illuminate\Support\Str::random(16);
        
        $user = User::create([
            'first_name' => $data['first_name'],
            'last_name' => $data['last_name'],
            'email' => $data['email'],
            'provider' => $data['provider'] ?? null,
            'provider_id' => $data['provider_id'] ?? null,
            'role_id' => $data['role_id'],
            'password' => Hash::make($data['password']),
        ]);

        $token = Password::broker()->createToken($user);
        $user->sendPasswordResetNotification($token);

        return $user;
    }

    public function resetPasswordWithToken(array $data): void
    {
        $status = Password::broker()->reset(
            $data,
            function ($user, $password) {
                $user->forceFill([
                    'password' => Hash::make($password)
                ])->setRememberToken(\Illuminate\Support\Str::random(60));

                $user->save();

                $user->tokens()->delete();
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
}
