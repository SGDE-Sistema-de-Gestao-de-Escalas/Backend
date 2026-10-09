<?php

namespace App\Http\Controllers\API\Assistants;

use App\Models\Assistants\Assistant;
use App\Http\Requests\Assistants\StoreAssistantRequest;
use App\Http\Requests\Assistants\UpdateAssistantRequest;
use App\Http\Controllers\Controller;
use App\Http\Resources\AssistantResource;
use App\Services\AssistantService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;

class AssistantController extends Controller
{
    public function __construct(
        private readonly AssistantService $assistantService
    ) {}

    /**
     * Display a listing of the resource.
     *
     * Filtros (query string):
     *  - status: "active" (default) | "inactive" (apagados) | "all"
     *  - search: nome, apelido, email ou nº mecanográfico
     *  - per_page: default 20, máx. 100
     * A escola vem do header X-School-ID; sem header lista todas.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        Gate::authorize('viewAny', Assistant::class);

        $schoolId = $request->header('X-School-ID');
        $search = trim((string) $request->query('search', ''));
        $status = $request->query('status', 'active');
        $perPage = min(max((int) $request->query('per_page', 20), 1), 100);

        $assistants = Assistant::query()
            ->with(['user', 'school'])
            ->when($status === 'inactive', fn ($query) => $query->whereHas('user', fn ($uq) => $uq->where('is_active', false)))
            ->when($status === 'active', fn ($query) => $query->whereHas('user', fn ($uq) => $uq->where('is_active', true)))
            ->when($status === 'trashed', fn ($query) => $query->onlyTrashed())
            ->when($status === 'all', fn ($query) => $query->withTrashed())
            ->when($schoolId, fn ($query) => $query->where('school_id', $schoolId))
            ->when($search !== '', function ($query) use ($search) {
                $query->where(function ($query) use ($search) {
                    $query->where('internal_number', 'like', "%{$search}%")
                        ->orWhereHas('user', fn ($userQuery) => $userQuery
                            ->withTrashed()
                            ->where('email', 'like', "%{$search}%")
                            ->orWhere('first_name', 'like', "%{$search}%")
                            ->orWhere('last_name', 'like', "%{$search}%"));
                });
            })
            ->orderBy('internal_number')
            ->paginate($perPage)
            ->withQueryString();

        return AssistantResource::collection($assistants);
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(StoreAssistantRequest $request): JsonResponse
    {
        $assistant = $this->assistantService->create($request->validated());

        return (new AssistantResource($assistant))
            ->response()
            ->setStatusCode(201);
    }

    /**
     * Display the specified resource.
     */
    public function show(Assistant $assistant): AssistantResource
    {
        Gate::authorize('view', $assistant);

        return new AssistantResource($assistant->load(['user', 'school', 'assistantScheduleProfiles.shifts.shiftDays']));
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(UpdateAssistantRequest $request, Assistant $assistant): AssistantResource
    {
        $updatedAssistant = $this->assistantService->update($assistant, $request->validated());

        return new AssistantResource($updatedAssistant);
    }


    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Assistant $assistant): JsonResponse
    {
        Gate::authorize('delete', $assistant);

        $this->assistantService->delete($assistant);

        return response()->json(['message' => 'Assistente eliminado com sucesso.']);
    }

    /**
     * Verificar elegibilidade para anonimização.
     * GET /api/assistants/{id}/can-anonymize
     */
    public function canAnonymize(Assistant $assistant): JsonResponse
    {
        Gate::authorize('anonymize', $assistant);

        $result = $this->assistantService->checkCanAnonymize($assistant);

        return response()->json($result);
    }

    /**
     * Anonimizar assistente (Direito ao Esquecimento RGPD).
     * POST /api/assistants/{id}/anonymize
     */
    public function anonymize(Assistant $assistant): JsonResponse
    {
        Gate::authorize('anonymize', $assistant);

        $check = $this->assistantService->checkCanAnonymize($assistant);

        if (! $check['can_anonymize']) {
            return response()->json([
                'message' => $check['reason'],
                'can_anonymize' => false,
                'has_future_schedules' => $check['has_future_schedules'],
                'future_schedules_count' => $check['future_schedules_count'],
            ], 422);
        }

        $anonymized = $this->assistantService->anonymize($assistant);

        return response()->json([
            'message' => 'Dados do assistente anonimizados com sucesso. O histórico de escalas passadas foi preservado.',
            'assistant' => [
                'id' => $anonymized->id,
                'name' => 'Assistente Anonimizado',
                'is_active' => false,
                'is_anonymized' => true,
            ],
        ], 200);
    }
}
