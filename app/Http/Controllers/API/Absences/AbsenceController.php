<?php

namespace App\Http\Controllers\API\Absences;

use App\Http\Controllers\Controller;
use App\Http\Requests\Absences\StoreAbsenceRequest;
use App\Http\Requests\Absences\UpdateAbsenceRequest;
use App\Http\Resources\AbsenceResource;
use App\Models\Absences\Absence;
use App\Services\AbsenceService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class AbsenceController extends Controller
{
    public function __construct(
        private readonly AbsenceService $absenceService
    ) {}

    /**
     * Display a listing of the absences.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        Gate::authorize('viewAny', Absence::class);

        $user = $request->user();
        $schoolId = $request->header('X-School-ID');
        $status = $request->query('status');
        $assistantId = $request->query('assistant_id');
        $startDate = $request->query('start_date');
        $endDate = $request->query('end_date');
        $search = trim((string) $request->query('search', ''));
        $perPage = $request->query('per_page');

        $query = Absence::query()
            ->with(['assistant.user', 'absenceType', 'createdByUser', 'justifiedByUser']);

        // Se for assistente (não-admin), só lista as suas próprias ausências
        if ($user && ! $user->isAdmin()) {
            $assistant = $user->assistant;
            $query->where('assistant_id', $assistant?->id ?? '00000000-0000-0000-0000-000000000000');
        } else {
            $query->when($schoolId, function ($q) use ($schoolId) {
                $q->whereHas('assistant', fn ($aq) => $aq->where('school_id', $schoolId));
            })
            ->when($assistantId, function ($q) use ($assistantId) {
                $q->where('assistant_id', $assistantId);
            });
        }

        $query->when($status && in_array($status, ['pending', 'justified', 'unjustified'], true), function ($q) use ($status) {
            $q->where('status', $status);
        })
        ->when($startDate, function ($q) use ($startDate) {
            $q->whereDate('valid_from', '>=', $startDate);
        })
        ->when($endDate, function ($q) use ($endDate) {
            $q->whereDate('valid_until', '<=', $endDate);
        })
        ->when($search !== '', function ($q) use ($search) {
            $q->where(function ($subQ) use ($search) {
                $subQ->whereHas('assistant.user', function ($uq) use ($search) {
                    $uq->where('first_name', 'like', "%{$search}%")
                        ->orWhere('last_name', 'like', "%{$search}%");
                })
                ->orWhereHas('absenceType', fn ($tq) => $tq->where('name', 'like', "%{$search}%"))
                ->orWhere('justification', 'like', "%{$search}%")
                ->orWhere('notes', 'like', "%{$search}%");
            });
        })
        ->orderByDesc('valid_from')
        ->orderByDesc('created_at');

        if ($perPage === 'all') {
            return AbsenceResource::collection($query->get());
        }

        $perPageInt = min(max((int) ($perPage ?: 20), 1), 100);

        return AbsenceResource::collection($query->paginate($perPageInt)->withQueryString());
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

        return (new AbsenceResource($absence))->response()->setStatusCode(201);
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

        return new AbsenceResource($updated);
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

        if (! $absence->document_path || ! Storage::disk('local')->exists($absence->document_path)) {
            abort(404, 'Documento comprovativo não encontrado.');
        }

        return Storage::disk('local')->response($absence->document_path);
    }
}
