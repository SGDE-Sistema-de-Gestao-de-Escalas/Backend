<?php

namespace App\Http\Controllers\API\Schools;

use App\Http\Controllers\Controller;
use App\Http\Requests\Schools\StoreSchoolOperatingExceptionRequest;
use App\Http\Requests\Schools\UpdateSchoolOperatingExceptionRequest;
use App\Http\Resources\SchoolOperatingExceptionResource;
use App\Models\Schools\SchoolOperatingException;
use App\Services\SchoolOperatingExceptionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class SchoolOperatingExceptionController extends Controller
{
    public function __construct(
        private readonly SchoolOperatingExceptionService $exceptionService
    ) {}

    /**
     * Display a listing of the resource.
     *
     * Com o cabeçalho X-School-ID lista só as exceções dessa escola. Os
     * assistentes têm de o indicar; o administrador pode omiti-lo para ver as
     * de todas as escolas. Da mais recente para a mais antiga. Filtro
     * opcional: ?status=upcoming|active|ended.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        Gate::authorize('viewAny', SchoolOperatingException::class);

        $filters = $request->validate([
            'status' => ['sometimes', Rule::in([
                SchoolOperatingExceptionService::STATUS_UPCOMING,
                SchoolOperatingExceptionService::STATUS_ACTIVE,
                SchoolOperatingExceptionService::STATUS_ENDED,
            ])],
        ]);

        $schoolId = $request->attributes->get('school_id');

        abort_if(
            $schoolId === null && ! $request->user()->isAdmin(),
            422,
            'Indique a escola através do cabeçalho X-School-ID.'
        );

        $query = SchoolOperatingException::query()
            ->when($schoolId, fn ($query) => $query->where('school_id', $schoolId));

        if (isset($filters['status'])) {
            $this->exceptionService->filterByStatus($query, $filters['status']);
        }

        return SchoolOperatingExceptionResource::collection(
            $query->orderByDesc('valid_from')->get()
        );
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(StoreSchoolOperatingExceptionRequest $request): JsonResponse
    {
        $exception = $this->exceptionService->create($request->validated());

        return (new SchoolOperatingExceptionResource($exception))
            ->additional(['message' => 'Exceção ao horário criada com sucesso.'])
            ->response()
            ->setStatusCode(201);
    }

    /**
     * Display the specified resource.
     */
    public function show(Request $request, SchoolOperatingException $operatingException): SchoolOperatingExceptionResource
    {
        Gate::authorize('view', $operatingException);

        $this->ensureInSchoolContext($request, $operatingException);

        return new SchoolOperatingExceptionResource($operatingException);
    }

    /**
     * Update the specified resource in storage.
     *
     * Futura: tudo. Em curso: só valid_until. Terminada: nada (409).
     */
    public function update(UpdateSchoolOperatingExceptionRequest $request, SchoolOperatingException $operatingException): SchoolOperatingExceptionResource
    {
        $this->ensureInSchoolContext($request, $operatingException);

        $updated = $this->exceptionService->update($operatingException, $request->validated());

        return (new SchoolOperatingExceptionResource($updated))
            ->additional(['message' => 'Exceção ao horário atualizada com sucesso.']);
    }

    /**
     * Remove the specified resource from storage.
     *
     * A verificação (só exceções futuras se eliminam) é feita dentro de
     * exceptionService->delete(), no instante deste pedido.
     */
    public function destroy(Request $request, SchoolOperatingException $operatingException): JsonResponse
    {
        Gate::authorize('delete', $operatingException);

        $this->ensureInSchoolContext($request, $operatingException);

        $this->exceptionService->delete($operatingException);

        return response()->json(['message' => 'Exceção ao horário removida com sucesso.']);
    }

    /**
     * Se o pedido indica uma escola (X-School-ID), a exceção tem de ser dessa
     * escola. Responde 404 para não revelar exceções de outras escolas.
     */
    private function ensureInSchoolContext(Request $request, SchoolOperatingException $exception): void
    {
        $schoolId = $request->attributes->get('school_id');

        abort_if($schoolId !== null && $schoolId !== $exception->school_id, 404);
    }
}
