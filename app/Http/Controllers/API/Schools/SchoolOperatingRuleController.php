<?php

namespace App\Http\Controllers\API\Schools;

use App\Http\Controllers\Controller;
use App\Http\Requests\Schools\StoreSchoolOperatingRuleRequest;
use App\Http\Requests\Schools\UpdateSchoolOperatingRuleRequest;
use App\Http\Resources\SchoolOperatingRuleResource;
use App\Models\Schools\SchoolOperatingRule;
use App\Services\SchoolOperatingRuleService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class SchoolOperatingRuleController extends Controller
{
    public function __construct(
        private readonly SchoolOperatingRuleService $operatingRuleService
    ) {}

    /**
     * Display a listing of the resource.
     *
     * Com o cabeçalho X-School-ID lista só as versões dessa escola. Os
     * assistentes têm de o indicar; o administrador pode omiti-lo para ver as
     * de todas as escolas. Da mais recente para a mais antiga. Filtro
     * opcional: ?status=upcoming|current|superseded.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        Gate::authorize('viewAny', SchoolOperatingRule::class);

        $filters = $request->validate([
            'status' => ['sometimes', Rule::in([
                SchoolOperatingRuleService::STATUS_UPCOMING,
                SchoolOperatingRuleService::STATUS_CURRENT,
                SchoolOperatingRuleService::STATUS_SUPERSEDED,
            ])],
        ]);

        $schoolId = $request->attributes->get('school_id');

        abort_if(
            $schoolId === null && ! $request->user()->isAdmin(),
            422,
            'Indique a escola através do cabeçalho X-School-ID.'
        );

        $rules = SchoolOperatingRule::withNextVersion()
            ->with('schoolOperatingRuleDays')
            ->when($schoolId, fn ($query) => $query->where('school_operating_rules.school_id', $schoolId))
            ->when(
                isset($filters['status']),
                fn ($query) => $query->inStatus($filters['status'], today()->toDateString())
            )
            ->orderByDesc('school_operating_rules.valid_from')
            ->get();

        return SchoolOperatingRuleResource::collection($rules);
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(StoreSchoolOperatingRuleRequest $request): JsonResponse
    {
        $rule = $this->operatingRuleService->create($request->validated());

        return (new SchoolOperatingRuleResource($rule))
            ->additional(['message' => 'Versão do horário fixo criada com sucesso.'])
            ->response()
            ->setStatusCode(201);
    }

    /**
     * Display the specified resource.
     */
    public function show(Request $request, SchoolOperatingRule $operatingRule): SchoolOperatingRuleResource
    {
        Gate::authorize('view', $operatingRule);

        $this->ensureInSchoolContext($request, $operatingRule);

        return new SchoolOperatingRuleResource($this->operatingRuleService->find($operatingRule->getKey()));
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(UpdateSchoolOperatingRuleRequest $request, SchoolOperatingRule $operatingRule): SchoolOperatingRuleResource
    {
        $this->ensureInSchoolContext($request, $operatingRule);

        $updated = $this->operatingRuleService->update($operatingRule, $request->validated());

        return (new SchoolOperatingRuleResource($updated))
            ->additional(['message' => 'Versão do horário fixo atualizada com sucesso.']);
    }

    /**
     * Remove the specified resource from storage.
     *
     * A verificação (só versões futuras se eliminam) é feita dentro de
     * operatingRuleService->delete(), no instante deste pedido.
     */
    public function destroy(Request $request, SchoolOperatingRule $operatingRule): JsonResponse
    {
        Gate::authorize('delete', $operatingRule);

        $this->ensureInSchoolContext($request, $operatingRule);

        $this->operatingRuleService->delete($operatingRule);

        return response()->json(['message' => 'Versão do horário fixo removida com sucesso.']);
    }

    /**
     * Se o pedido indica uma escola (X-School-ID), a versão tem de ser dessa
     * escola. Responde 404 para não revelar versões de outras escolas.
     */
    private function ensureInSchoolContext(Request $request, SchoolOperatingRule $rule): void
    {
        $schoolId = $request->attributes->get('school_id');

        abort_if($schoolId !== null && $schoolId !== $rule->school_id, 404);
    }
}
