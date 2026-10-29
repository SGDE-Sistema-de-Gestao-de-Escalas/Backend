<?php

namespace App\Http\Controllers\API\Absences;

use App\Http\Controllers\Controller;
use App\Http\Requests\Absences\StoreAbsenceRequest;
use App\Http\Requests\Absences\UpdateAbsenceRequest;
use App\Http\Resources\AbsenceResource;
use App\Models\Absences\Absence;
use App\Services\AbsenceService;
use App\Services\NotificationService;
use App\Services\ScheduleIntegrityService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class AbsenceController extends Controller
{
    public function __construct(
        private readonly AbsenceService $absenceService,
        private readonly ScheduleIntegrityService $scheduleIntegrityService,
        private readonly NotificationService $notifications,
    ) {}

    /**
     * Display a listing of the absences.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        Gate::authorize('viewAny', Absence::class);

        $user = $request->user();
        $schoolId = $request->attributes->get('school_id')
            ?? $request->header('X-School-ID')
            ?? $user?->school_id
            ?? $user?->assistant?->school_id;

        $baseQuery = Absence::query();

        // Se for assistente (não-admin), só lista as suas próprias ausências
        if ($user && ! $user->isAdmin()) {
            $assistant = $user->assistant;
            $baseQuery->where('assistant_id', $assistant?->id ?? '00000000-0000-0000-0000-000000000000');
        } elseif ($schoolId) {
            $baseQuery->whereHas('assistant', fn ($aq) => $aq->where('school_id', $schoolId));
        }

        // Contadores globais para as Tabs (rápidos via SQL, respeitando o contexto da escola/utilizador)
        $counts = [
            'all' => (clone $baseQuery)->count(),
            'pending' => (clone $baseQuery)->where('status', 'pending')->count(),
            'justified' => (clone $baseQuery)->where('status', 'justified')->count(),
            'unjustified' => (clone $baseQuery)->where('status', 'unjustified')->count(),
        ];

        $query = (clone $baseQuery)
            ->with(['assistant.user', 'absenceType', 'createdByUser', 'justifiedByUser']);

        // 1. Filtro por Assistente
        if ($user && $user->isAdmin() && $request->filled('assistant_id') && $request->input('assistant_id') !== 'all') {
            $query->where('assistant_id', $request->input('assistant_id'));
        }

        // 2. Filtro por Estado (pending, justified, unjustified)
        if ($request->filled('status') && $request->input('status') !== 'all') {
            $status = $request->input('status');
            if (in_array($status, ['pending', 'justified', 'unjustified'], true)) {
                $query->where('status', $status);
            }
        }

        // 3. Filtro por Datas (Lógica de sobreposição de intervalos)
        if ($request->filled('start_date') || $request->filled('valid_from')) {
            $startDate = $request->input('start_date', $request->input('valid_from'));
            $query->where(function ($q) use ($startDate) {
                $q->whereDate('valid_until', '>=', $startDate)
                    ->orWhere(function ($sub) use ($startDate) {
                        $sub->whereNull('valid_until')->whereDate('valid_from', '>=', $startDate);
                    });
            });
        }

        if ($request->filled('end_date') || $request->filled('valid_until')) {
            $endDate = $request->input('end_date', $request->input('valid_until'));
            $query->whereDate('valid_from', '<=', $endDate);
        }

        // 4. Pesquisa Textual
        $search = trim((string) $request->input('search', ''));
        if ($search !== '') {
            $query->where(function ($subQ) use ($search) {
                $subQ->whereHas('assistant.user', function ($uq) use ($search) {
                    $uq->where('first_name', 'like', "%{$search}%")
                        ->orWhere('last_name', 'like', "%{$search}%")
                        ->orWhere('email', 'like', "%{$search}%");
                })
                ->orWhereHas('assistant', fn ($aq) => $aq->where('internal_number', 'like', "%{$search}%"))
                ->orWhereHas('absenceType', fn ($tq) => $tq->where('name', 'like', "%{$search}%"))
                ->orWhere('justification', 'like', "%{$search}%")
                ->orWhere('notes', 'like', "%{$search}%");
            });
        }

        // 5. Ordenação (mais recentes primeiro por defeito)
        $allowedSorts = ['valid_from', 'valid_until', 'total_days', 'status', 'created_at'];
        $sortBy = in_array($request->input('sort_by'), $allowedSorts, true) ? $request->input('sort_by') : 'valid_from';
        $sortDir = strtolower((string) $request->input('sort_direction', 'desc')) === 'asc' ? 'asc' : 'desc';

        $query->orderBy($sortBy, $sortDir)->orderByDesc('created_at');

        // 6. Suporte para devolver todos sem paginação (per_page = -1 ou 'all')
        $perPageInput = $request->input('per_page', 8);
        if ($perPageInput === 'all' || (int) $perPageInput === -1) {
            $absences = $query->get();
            $total = $absences->count();

            return AbsenceResource::collection($absences)->additional([
                'meta' => [
                    'current_page' => 1,
                    'last_page' => 1,
                    'per_page' => $total,
                    'total' => $total,
                    'counts' => $counts,
                ],
                'counts' => $counts,
            ]);
        }

        // 7. Paginação Normal (default 8)
        $perPage = min(max((int) $perPageInput ?: 8, 1), 100);
        $absences = $query->paginate($perPage)->withQueryString();

        return AbsenceResource::collection($absences)->additional([
            'meta' => [
                'counts' => $counts,
            ],
            'counts' => $counts,
        ]);
    }

    /**
     * Store a newly created absence.
     */
    public function store(StoreAbsenceRequest $request): JsonResponse
    {
        Gate::authorize('create', Absence::class);

        $validated = $request->validated();
        $document = $request->file('document');

        $absence = $this->absenceService->create($validated, $document, $request->user());

        $schoolId = $absence->assistant?->school_id
            ?? $request->attributes->get('school_id')
            ?? $request->header('X-School-ID')
            ?? $request->user()?->school_id;

        $impact = null;
        if ($schoolId && $absence->valid_from) {
            $impact = $this->scheduleIntegrityService->checkImpact(
                schoolId: (string) $schoolId,
                startDate: $absence->valid_from->format('Y-m-d'),
                endDate: $absence->valid_until?->format('Y-m-d'),
                eventType: 'absence',
                assistantId: $absence->assistant_id
            );
        }

        // Notificações para os administradores (nunca falham a operação principal).
        $this->notifications->absenceRegistered($absence, $request->user());
        if ($schoolId && $impact) {
            $this->notifications->scheduleRecalculationRequired(
                (string) $schoolId,
                $impact,
                $absence->assistant?->user?->name,
                'falta',
                $request->user()
            );
        }

        $missingDocument = (bool) ($absence->absenceType?->requires_document ?? false) && empty($absence->document_path);
        $warning = $missingDocument
            ? 'Este tipo de ausência requer a apresentação de documento comprovativo.'
            : null;

        $responseResource = (new AbsenceResource($absence))
            ->additional([
                'message' => $missingDocument
                    ? 'Ausência registada com sucesso. Atenção: falta anexar o documento comprovativo.'
                    : 'Ausência registada com sucesso.',
                'warning' => $warning,
                'impact' => $impact,
            ]);

        return $responseResource
            ->response()
            ->setStatusCode(201);
    }

    /**
     * Display the specified absence.
     */
    public function show(Absence $absence): AbsenceResource
    {
        Gate::authorize('view', $absence);

        $absence->load(['assistant.user', 'absenceType', 'createdByUser', 'justifiedByUser']);

        return new AbsenceResource($absence);
    }

    /**
     * Update the specified absence.
     */
    public function update(UpdateAbsenceRequest $request, Absence $absence): AbsenceResource
    {
        Gate::authorize('update', $absence);

        $validated = $request->validated();
        $document = $request->file('document');

        $updated = $this->absenceService->update($absence, $validated, $document, $request->user());

        $schoolId = $updated->assistant?->school_id
            ?? $request->attributes->get('school_id')
            ?? $request->header('X-School-ID')
            ?? $request->user()?->school_id;

        $impact = null;
        if ($schoolId && $updated->valid_from) {
            $impact = $this->scheduleIntegrityService->checkImpact(
                schoolId: (string) $schoolId,
                startDate: $updated->valid_from->format('Y-m-d'),
                endDate: $updated->valid_until?->format('Y-m-d'),
                eventType: 'absence',
                assistantId: $updated->assistant_id
            );
        }

        // Notificações para os administradores (nunca falham a operação principal).
        if ($document) {
            $this->notifications->absenceDocumentUploaded($updated, $request->user());
        }
        if ($schoolId && $impact) {
            $this->notifications->scheduleRecalculationRequired(
                (string) $schoolId,
                $impact,
                $updated->assistant?->user?->name,
                'falta',
                $request->user()
            );
        }

        $missingDocument = (bool) ($updated->absenceType?->requires_document ?? false) && empty($updated->document_path);
        $warning = $missingDocument
            ? 'Este tipo de ausência requer a apresentação de documento comprovativo.'
            : null;

        return (new AbsenceResource($updated))
            ->additional([
                'message' => $missingDocument
                    ? 'Ausência atualizada com sucesso. Atenção: falta anexar o documento comprovativo.'
                    : 'Ausência atualizada com sucesso.',
                'warning' => $warning,
                'impact' => $impact,
            ]);
    }

    /**
     * Remove the specified absence from storage.
     */
    public function destroy(Absence $absence): JsonResponse
    {
        Gate::authorize('delete', $absence);

        $this->absenceService->delete($absence);

        return response()->json([
            'message' => 'Ausência eliminada com sucesso.',
        ]);
    }

    /**
     * Download or view the justification document for the specified absence.
     */
    public function downloadDocument(Absence $absence): StreamedResponse
    {
        Gate::authorize('view', $absence);

        if (! $absence->document_path) {
            abort(404, 'Documento comprovativo não encontrado.');
        }

        $disk = config('filesystems.documents_disk');

        if (! Storage::disk($disk)->exists($absence->document_path)) {
            abort(404, 'Ficheiro do documento comprovativo não encontrado no servidor.');
        }

        return Storage::disk($disk)->response($absence->document_path, null, ['X-Content-Type-Options' => 'nosniff']);
    }
}
