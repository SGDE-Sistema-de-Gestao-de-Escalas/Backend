<?php

namespace App\Http\Controllers\API\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\RequestDeactivationRequest;
use App\Services\UserService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

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

    public function export(Request $request): JsonResponse
    {
        $data = $this->userService->exportPersonalData($request->user());

        $filename = 'dados-pessoais-' . $request->user()->id . '.json';

        return response()->json($data, 200, [
            'Content-Disposition' => 'attachment; filename="' . $filename . '"',
            'Content-Type'        => 'application/json',
        ]);
    }
}

