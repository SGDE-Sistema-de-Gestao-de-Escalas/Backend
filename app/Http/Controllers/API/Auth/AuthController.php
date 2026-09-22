<?php

namespace App\Http\Controllers\API\Auth;

use App\Http\Controllers\Controller;
use App\Http\DTOs\UserDTO;
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
        ]);

        $authData = $this->authService->attemptLogin($credentials);
        
        if (!$authData) {
            return response()->json(['message' => 'Credenciais inválidas.'], 401);
        }

        return response()->json([
            'access_token' => $authData['token'],
            'user' => new UserDTO($authData['user']),
        ]);
    }

    public function updatePassword(Request $request): JsonResponse
    {
        $request->validate([
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
}