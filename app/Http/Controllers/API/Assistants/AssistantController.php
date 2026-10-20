<?php

namespace App\Http\Controllers\API\Assistants;

use App\Models\Assistants\Assistant;
use App\Models\Auth\User;
use App\Http\Requests\Assistants\StoreAssistantRequest;
use App\Http\Requests\Assistants\UpdateAssistantRequest;
use App\Http\Controllers\Controller;
use App\Http\Resources\AssistantResource;
use App\Services\AssistantService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class AssistantController extends Controller
{
    public function __construct(
        private readonly AssistantService $assistantService
    ) {}

    /**
     * Display a listing of the resource.
     *
     * Filtros (query string):
     *  - page: integer (default 1)
     *  - per_page: integer (default 10; -1 devolve todos sem paginação)
     *  - status: "active" (default) | "inactive" | "all"
     *  - search: name, first_name, last_name, email ou internal_number
     *  - sort_by: "name" (default) | "internal_number"
     *  - sort_direction: "asc" (default) | "desc"
     * A escola vem do header X-School-ID.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        Gate::authorize('viewAny', Assistant::class);

        $schoolId = $request->header('X-School-ID') ?? $request->query('school_id');

        $query = Assistant::withTrashed()
            ->with(['user', 'school'])
            ->when($schoolId, fn ($q) => $q->where('school_id', $schoolId));

        // 1. Contadores para as Tabs (rápidos e sem paginação)
        $countsQuery = clone $query;
        $counts = [
            'all' => (clone $countsQuery)->count(),
            'active' => (clone $countsQuery)
                ->whereNull('assistants.deleted_at')
                ->where(fn ($q) => $q->where('is_anonymized', false)->orWhereNull('is_anonymized'))
                ->whereHas('user', fn ($uq) => $uq->whereNull('deleted_at')->where('is_active', true))
                ->count(),
            'inactive' => (clone $countsQuery)
                ->where(function ($q) {
                    $q->whereNotNull('assistants.deleted_at')
                        ->orWhere('is_anonymized', true)
                        ->orWhereDoesntHave('user', fn ($uq) => $uq->whereNull('deleted_at')->where('is_active', true));
                })
                ->count(),
        ];

        // 2. Filtro de Estado
        $status = $request->input('status', 'active');
        if ($status === 'active') {
            $query->whereNull('assistants.deleted_at')
                ->where(fn ($q) => $q->where('is_anonymized', false)->orWhereNull('is_anonymized'))
                ->whereHas('user', fn ($uq) => $uq->whereNull('deleted_at')->where('is_active', true));
        } elseif ($status === 'inactive') {
            $query->where(function ($q) {
                $q->whereNotNull('assistants.deleted_at')
                    ->orWhere('is_anonymized', true)
                    ->orWhereDoesntHave('user', fn ($uq) => $uq->whereNull('deleted_at')->where('is_active', true));
            });
        } elseif ($status === 'trashed') {
            $query->onlyTrashed();
        }

        // 3. Pesquisa Textual (name, first_name, last_name, email, internal_number)
        $search = trim((string) $request->input('search', ''));
        if ($search !== '') {
            $words = array_values(array_filter(preg_split('/\s+/', $search) ?: []));

            $query->where(function ($q) use ($search, $words) {
                $q->where('internal_number', 'like', "%{$search}%")
                    ->orWhereHas('user', function ($uq) use ($search, $words) {
                        $uq->withTrashed()->where(function ($sub) use ($search, $words) {
                            $sub->where('first_name', 'like', "%{$search}%")
                                ->orWhere('last_name', 'like', "%{$search}%")
                                ->orWhere('email', 'like', "%{$search}%");

                            if (count($words) > 1) {
                                $sub->orWhere(function ($nameQuery) use ($words) {
                                    foreach ($words as $word) {
                                        $nameQuery->where(function ($wq) use ($word) {
                                            $wq->where('first_name', 'like', "%{$word}%")
                                                ->orWhere('last_name', 'like', "%{$word}%");
                                        });
                                    }
                                });
                            }
                        });
                    });
            });
        }

        // 4. Ordenação
        $sortBy = $request->input('sort_by', 'name');
        $sortDir = strtolower((string) $request->input('sort_direction', 'asc')) === 'desc' ? 'desc' : 'asc';

        if ($sortBy === 'internal_number') {
            $query->orderBy('assistants.internal_number', $sortDir);
        } else {
            $query->orderBy(
                User::withTrashed()
                    ->select('first_name')
                    ->whereColumn('users.id', 'assistants.user_id')
                    ->limit(1),
                $sortDir
            )->orderBy(
                User::withTrashed()
                    ->select('last_name')
                    ->whereColumn('users.id', 'assistants.user_id')
                    ->limit(1),
                $sortDir
            );
        }

        // 5. Suporte para devolver todos nos dropdowns (per_page = -1)
        if ((int) $request->input('per_page', 10) === -1) {
            $assistants = $query->get();

            return AssistantResource::collection($assistants)->additional([
                'counts' => $counts,
            ]);
        }

        // 6. Paginação Normal
        $perPage = max((int) $request->input('per_page', 10), 1);
        $assistants = $query->paginate($perPage)->withQueryString();

        return AssistantResource::collection($assistants)->additional([
            'counts' => $counts,
        ]);
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(StoreAssistantRequest $request): JsonResponse
    {
        $file = $request->file('criminal_record');
        $assistant = $this->assistantService->create($request->validated(), $file);

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
        $file = $request->file('criminal_record');
        $updatedAssistant = $this->assistantService->update($assistant, $request->validated(), $file);

        return new AssistantResource($updatedAssistant);
    }

    /**
     * Download or view the criminal record document.
     */
    public function downloadCriminalRecord(Assistant $assistant): StreamedResponse
    {
        Gate::authorize('view', $assistant);

        if (! $assistant->criminal_record_path) {
            abort(404, 'Registo criminal não encontrado.');
        }

        $disk = config('filesystems.documents_disk');

        if (! Storage::disk($disk)->exists($assistant->criminal_record_path)) {
            abort(404, 'Ficheiro do registo criminal não encontrado no servidor.');
        }

        return Storage::disk($disk)->response($assistant->criminal_record_path);
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
