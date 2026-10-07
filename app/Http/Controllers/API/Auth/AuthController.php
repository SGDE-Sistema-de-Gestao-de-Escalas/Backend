<?php

namespace App\Http\Controllers\API\Auth;

use App\Http\Controllers\Controller;
use App\Http\Resources\UserResource;
use App\Services\AuthService;
use App\Services\UserService;
use App\Http\Requests\Auth\UpdateUserRequest;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;

class AuthController extends Controller
{
    public function __construct(
        private readonly AuthService $authService,
        private readonly UserService $userService,
    ) {}

    public function login(Request $request): JsonResponse
    {
        $credentials = $request->validate([
            'email' => 'required|email',
            'password' => 'required|string',
            'remember_me' => 'boolean'
        ]);

        $cookieMinutes = !empty($credentials['remember_me']) ? 60 * 24 * 30 : 60 * 24; // 30 dias ou 24 horas

        if (!empty($credentials['remember_me'])) {
            config(['sanctum.expiration' => $cookieMinutes]);
        }

        $authData = $this->authService->attemptLogin($credentials);

        $cookie = cookie(
            name: 'access_token',
            value: $authData['token'],
            minutes: $cookieMinutes,
            path: '/',
            domain: null,
            secure: config('app.env') === 'production',
            httpOnly: true,
            raw: false,
            sameSite: 'Lax'
        );

        return response()->json([
            'access_token' => $authData['token'],
            'user' => new UserResource($authData['user']),
        ])->withCookie($cookie);
    }

    public function me(Request $request): UserResource
    {
        $user = $request->user();

        $user->load('role');

        return new UserResource($user);
    }

    public function updateMe(UpdateUserRequest $request): UserResource
    {
        $user = $request->user();
        $updatedUser = $this->userService->update($user, $request->validated());

        return (new UserResource($updatedUser))
            ->additional(['message' => 'Perfil atualizado com sucesso.']);
    }

    public function destroyMe(Request $request): JsonResponse
    {
        $user = $request->user();

        Gate::authorize('delete', $user);

        $action = $this->userService->delete($user, $user);

        return response()->json([
            'message' => $action === 'anonymize'
                ? 'Conta anonimizada com sucesso. O histórico foi mantido.'
                : 'Conta eliminada definitivamente com sucesso.',
            'delete_action' => $action,
        ]);
    }

    public function updatePassword(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'current_password' => 'required|string|current_password:sanctum',
            'new_password' => 'required|string|min:8|confirmed',
        ]);

        $this->authService->updatePassword($request->user(), $validated['new_password']);

        return response()->json(['message' => 'Password atualizada com sucesso.']);
    }

    public function logout(Request $request): JsonResponse
    {
        $this->authService->logout($request->user());

        return response()->json(['message' => 'Logout realizado com sucesso.'])
            ->withoutCookie('access_token');
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