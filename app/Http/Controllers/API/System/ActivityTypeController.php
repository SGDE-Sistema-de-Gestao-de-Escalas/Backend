<?php

namespace App\Http\Controllers\API\System;

use App\Http\Controllers\Controller;
use App\Http\Requests\System\StoreActivityTypeRequest;
use App\Http\Requests\System\UpdateActivityTypeRequest;
use App\Http\Resources\ActivityTypeResource;
use App\Models\System\ActivityType;
use App\Services\ActivityTypeService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;

class ActivityTypeController extends Controller
{
    public function __construct(
        private readonly ActivityTypeService $activityTypeService
    ) {}

    /**
     * Display a listing of the resource.
     *
     * Com o cabeçalho X-School-ID lista só as atividades dessa escola.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        Gate::authorize('viewAny', ActivityType::class);

        $schoolId = $request->attributes->get('school_id');

        $activityTypes = ActivityType::withCount(ActivityTypeService::blockingCounts())
            ->when($schoolId, fn ($query) => $query->where('school_id', $schoolId))
            ->orderBy('name')
            ->get();

        return ActivityTypeResource::collection($activityTypes);
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(StoreActivityTypeRequest $request): JsonResponse
    {
        $activityType = $this->activityTypeService->create($request->validated());

        return (new ActivityTypeResource($activityType))
            ->additional(['message' => 'Atividade criada com sucesso.'])
            ->response()
            ->setStatusCode(201);
    }

    /**
     * Display the specified resource.
     */
    public function show(ActivityType $activityType): ActivityTypeResource
    {
        Gate::authorize('view', $activityType);

        $activityType->loadCount(ActivityTypeService::blockingCounts());

        return new ActivityTypeResource($activityType);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(UpdateActivityTypeRequest $request, ActivityType $activityType): ActivityTypeResource
    {
        $updated = $this->activityTypeService->update($activityType, $request->validated());

        return (new ActivityTypeResource($updated))
            ->additional(['message' => $this->updateMessage($updated)]);
    }

    /**
     * Remove the specified resource from storage.
     *
     * A verificação de dependências é feita dentro de
     * activityTypeService->delete(), no instante deste pedido, e nunca a
     * partir de um `can_delete` visto antes pelo cliente.
     */
    public function destroy(ActivityType $activityType): JsonResponse
    {
        Gate::authorize('delete', $activityType);

        $this->activityTypeService->delete($activityType);

        return response()->json(['message' => 'Atividade removida com sucesso.']);
    }

    /**
     * Se o estado `active` mudou diz-o explicitamente, caso contrário
     * confirma a atualização dos dados.
     */
    private function updateMessage(ActivityType $activityType): string
    {
        if ($activityType->wasChanged('active')) {
            return $activityType->active
                ? 'Atividade ativada com sucesso.'
                : 'Atividade desativada com sucesso.';
        }

        return 'Dados da atividade atualizados com sucesso.';
    }
}
