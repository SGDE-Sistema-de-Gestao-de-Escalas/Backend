<?php

namespace App\Http\Controllers\API\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\RequestDeactivationRequest;
use App\Services\UserService;
use Barryvdh\DomPDF\Facade\Pdf;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

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

    public function export(Request $request): Response|JsonResponse
    {
        $user = $request->user();
        $data = $this->userService->exportPersonalData($user);

        if (strtolower((string) $request->query('format', 'json')) === 'pdf') {
            $logoPath = resource_path('views/exports/logo-horizontal.svg');
            $logoBase64 = is_file($logoPath)
                ? 'data:image/svg+xml;base64,' . base64_encode((string) file_get_contents($logoPath))
                : null;

            $perfil = $data['perfil'] ?? [];
            $assistente = $data['assistente'] ?? null;
            $fullName = trim(($perfil['first_name'] ?? '') . ' ' . ($perfil['last_name'] ?? '')) ?: 'Utilizador';

            $formatDate = static function (?string $iso, string $fmt = 'd/m/Y H:i'): string {
                if (! $iso) {
                    return '—';
                }
                try {
                    return Carbon::parse($iso)->format($fmt);
                } catch (\Throwable) {
                    return $iso;
                }
            };

            $pdf = Pdf::loadView('exports.personal-data-rgpd', [
                'logoBase64'            => $logoBase64,
                'fullName'              => $fullName,
                'perfil'                => $perfil,
                'assistente'            => $assistente,
                'exportedAtFormatted'   => Carbon::now()->format('d/m/Y H:i'),
                'createdAtFormatted'    => $formatDate($perfil['created_at'] ?? null),
                'updatedAtFormatted'    => $formatDate($perfil['updated_at'] ?? null),
                'birthDateFormatted'    => $formatDate($assistente['birth_date'] ?? null, 'd/m/Y'),
                'admissionDateFormatted'=> $formatDate($assistente['admission_date'] ?? null, 'd/m/Y'),
            ])->setPaper('a4', 'portrait');

            $pdfFilename = 'ficha-dados-pessoais-' . $user->id . '.pdf';

            return response($pdf->output(), 200, [
                'Content-Type'        => 'application/pdf',
                'Content-Disposition' => 'attachment; filename="' . $pdfFilename . '"',
            ]);
        }

        $filename = 'dados-pessoais-' . $user->id . '.json';

        return response()->json($data, 200, [
            'Content-Disposition' => 'attachment; filename="' . $filename . '"',
            'Content-Type'        => 'application/json',
        ]);
    }
}
