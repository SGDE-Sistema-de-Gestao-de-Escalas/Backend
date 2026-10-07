<?php

namespace App\Http\Controllers\API\Schools;

use App\Models\Schools\School;
use App\Http\Requests\Schools\StoreSchoolRequest;
use App\Http\Requests\Schools\UpdateSchoolRequest;
use App\Http\Controllers\Controller;
use App\Http\Resources\SchoolResource;
use App\Services\SchoolService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;

class SchoolController extends Controller
{
    public function __construct(
        private readonly SchoolService $schoolService
    ) {}

    /**
     * Display a listing of the resource.
     */
    public function index(): AnonymousResourceCollection
    {
        Gate::authorize('viewAny', School::class);

        $schools = School::withCount('assistants')
            ->withCount(SchoolService::blockingCounts())
            ->orderBy('name')
            ->get();

        return SchoolResource::collection($schools);
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(StoreSchoolRequest $request): JsonResponse
    {
        $school = $this->schoolService->create($request->validated());

        return (new SchoolResource($school))
            ->additional(['message' => 'Escola criada com sucesso.'])
            ->response()
            ->setStatusCode(201);
    }

    /**
     * Display the specified resource.
     */
    public function show(School $school): SchoolResource
    {
        Gate::authorize('view', $school);

        $school->loadCount('assistants')
            ->loadCount(SchoolService::blockingCounts());

        return new SchoolResource($school);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(UpdateSchoolRequest $request, School $school): SchoolResource
    {
        $updatedSchool = $this->schoolService->update($school, $request->validated());

        return (new SchoolResource($updatedSchool))
            ->additional(['message' => $this->updateMessage($updatedSchool)]);
    }

    /**
     * Remove the specified resource from storage.
     *
     * A verificação de dependências (can_delete) é feita dentro de
     * schoolService->delete(), mesmo em cima da linha bloqueada, no
     * instante exato deste pedido, nunca a partir de um valor
     * `can_delete` visto antes pelo cliente.
     */
    public function destroy(School $school): JsonResponse
    {
        Gate::authorize('delete', $school);

        $this->schoolService->delete($school);

        return response()->json(['message' => 'Escola removida com sucesso.']);
    }

    /**
     * Mensagem devolvida após uma atualização: se o estado `active` mudou
     * diz-o explicitamente, caso contrário confirma a atualização dos dados.
     */
    private function updateMessage(School $school): string
    {
        if ($school->wasChanged('active')) {
            return $school->active
                ? 'Escola ativada com sucesso.'
                : 'Escola desativada com sucesso.';
        }

        return 'Dados da escola atualizados com sucesso.';
    }
}
