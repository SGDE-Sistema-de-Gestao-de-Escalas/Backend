<?php

namespace App\Services;

use App\Models\User;
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

        if (!$user || !Hash::check($credentials['password'], $user->password)) {
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

        // Se o utilizador existe, associamos a conta social (caso ainda não esteja associada)
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
            'name' => $data['name'],
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
