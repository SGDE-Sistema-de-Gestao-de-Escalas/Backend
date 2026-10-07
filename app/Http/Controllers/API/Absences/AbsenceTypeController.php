<?php

namespace App\Http\Controllers\API\Absences;

use App\Http\Controllers\Controller;
use App\Http\Requests\Absences\StoreAbsenceTypeRequest;
use App\Http\Requests\Absences\UpdateAbsenceTypeRequest;
use App\Http\Resources\AbsenceTypeResource;
use App\Models\Absences\AbsenceType;
use App\Services\AbsenceTypeService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;

class AbsenceTypeController extends Controller
{
    public function __construct(
        private readonly AbsenceTypeService $absenceTypeService
    ) {}

    /**
     * Display a listing of the resource.
     */
    public function index(): AnonymousResourceCollection
    {
        Gate::authorize('viewAny', AbsenceType::class);

        $absenceTypes = AbsenceType::withCount(AbsenceTypeService::blockingCounts())
            ->orderBy('name')
            ->get();

        return AbsenceTypeResource::collection($absenceTypes);
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(StoreAbsenceTypeRequest $request): JsonResponse
    {
        $absenceType = $this->absenceTypeService->create($request->validated());

        return (new AbsenceTypeResource($absenceType))
            ->additional(['message' => 'Tipo de falta criado com sucesso.'])
            ->response()
            ->setStatusCode(201);
    }

    /**
     * Display the specified resource.
     */
    public function show(AbsenceType $absenceType): AbsenceTypeResource
    {
        Gate::authorize('view', $absenceType);

        $absenceType->loadCount(AbsenceTypeService::blockingCounts());

        return new AbsenceTypeResource($absenceType);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(UpdateAbsenceTypeRequest $request, AbsenceType $absenceType): AbsenceTypeResource
    {
        $updated = $this->absenceTypeService->update($absenceType, $request->validated());

        return (new AbsenceTypeResource($updated))
            ->additional(['message' => 'Tipo de falta atualizado com sucesso.']);
    }

    /**
     * Remove the specified resource from storage.
     *
     * A verificação de dependências é feita dentro de
     * absenceTypeService->delete(), no instante deste pedido, e nunca a partir
     * de um `can_delete` visto antes pelo cliente.
     */
    public function destroy(AbsenceType $absenceType): JsonResponse
    {
        Gate::authorize('delete', $absenceType);

        $this->absenceTypeService->delete($absenceType);

        return response()->json(['message' => 'Tipo de falta removido com sucesso.']);
    }
}
