<?php

namespace App\Http\Controllers\API\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\RequestDeactivationRequest;
use App\Services\UserService;
use Illuminate\Http\JsonResponse;

class PrivacyController extends Controller
{
    public function __construct(
        private readonly UserService $userService
    ) {}

    public function requestDeactivation(RequestDeactivationRequest $request): JsonResponse
    {
        $this->userService->requestDeactivation(
            $request->user(),
            $request->validated('reason')
        );

        return response()->json([
            'message' => 'Pedido de desativação submetido com sucesso. Os administradores foram notificados por email.',
        ]);
    }
}

