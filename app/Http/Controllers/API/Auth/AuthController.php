<?php

namespace App\Http\Controllers\API\Auth;

use App\Http\Controllers\Controller;
use App\Http\Resources\UserResource;
use App\Services\AuthService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

class AuthController extends Controller
{
    public function __construct(private readonly AuthService $authService) {}

    public function login(Request $request): JsonResponse
    {
        $credentials = $request->validate([
            'email' => 'required|email',
            'password' => 'required|string',
            'remember_me' => 'boolean'
        ]);

        if (!empty($credentials['remember_me'])) {
            config(['sanctum.expiration' => 60 * 24 * 30]); // 30 dias
        }

        $authData = $this->authService->attemptLogin($credentials);

        return response()->json([
            'access_token' => $authData['token'],
            'user' => new UserResource($authData['user']),
        ]);
    }

    public function updatePassword(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'current_password' => 'required|string',
            'new_password' => 'required|string|min:8|confirmed',
        ]);

        $this->authService->updatePassword($request->user(), $validated['new_password']);

        return response()->json(['message' => 'Password atualizada com sucesso.']);
    }

    public function logout(Request $request): JsonResponse
    {
        $this->authService->logout($request->user());

        return response()->json(['message' => 'Logout realizado com sucesso.']);
    }

    public function resetPassword(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'token' => 'required|string',
            'email' => 'required|email',
            'password' => 'required|string|min:8|confirmed',
        ]);

        $this->authService->resetPasswordWithToken($validated);

        return response()->json(['message' => 'Conta finalizada com sucesso. Vamos redirecioná-lo para a página de login.']);

    }

    public function requestPasswordReset(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'email' => 'required|email',
        ]);

        $this->authService->sendPasswordResetLink($validated['email']);

        return response()->json(['message' => 'Se o email existir na nossa base de dados, enviámos um link para repor a sua password.']);
    }
}