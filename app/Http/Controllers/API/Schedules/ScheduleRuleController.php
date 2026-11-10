<?php

namespace App\Http\Controllers\API\Schedules;

use App\Http\Controllers\Controller;
use App\Http\Requests\Schedules\ScheduleRuleRequest;
use App\Http\Requests\Schedules\StoreScheduleRuleRequest;
use App\Http\Requests\Schedules\UpdateScheduleRuleRequest;
use App\Http\Resources\Schedules\ScheduleRuleResource;
use App\Models\Schedules\ScheduleRule;
use App\Services\System\NotificationService;
use App\Services\Schedules\ScheduleRuleService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class ScheduleRuleController extends Controller
{
    public function __construct(
        private readonly ScheduleRuleService $service,
        private readonly NotificationService $notifications,
    ) {}

    public function index(ScheduleRuleRequest $request): AnonymousResourceCollection
    {
        return ScheduleRuleResource::collection(
            ScheduleRule::where('school_id', $request->attributes->get('school_id'))
                ->with('assistants.user')->orderBy('valid_from')->orderBy('id')->get()
        );
    }

    public function store(StoreScheduleRuleRequest $request): JsonResponse
    {
        $rule = $this->service->create($request->validated(), $request->attributes->get('school_id'));

        return (new ScheduleRuleResource($rule))
            ->additional(['message' => 'Regra criada com sucesso.'])->response()->setStatusCode(201);
    }

    public function show(ScheduleRuleRequest $request, ScheduleRule $scheduleRule): ScheduleRuleResource
    {
        return new ScheduleRuleResource($scheduleRule->load('assistants.user'));
    }

    public function update(UpdateScheduleRuleRequest $request, ScheduleRule $scheduleRule): ScheduleRuleResource
    {
        return (new ScheduleRuleResource($this->service->update($scheduleRule, $request->validated())))
            ->additional(['message' => 'Regra atualizada com sucesso.']);
    }

    public function destroy(ScheduleRuleRequest $request, ScheduleRule $scheduleRule): JsonResponse
    {
        $result = $this->service->delete($scheduleRule, $request->boolean('confirm'));

        if (! $result['deleted']) {
            return response()->json([
                'message' => 'Esta regra é usada em horários de hoje em diante. Confirme a eliminação para continuar.',
                'requires_confirmation' => true,
                'impact' => $result['impact'],
            ], 409);
        }

        $response = ['message' => 'Regra removida com sucesso.'];

        if ($result['impact'] !== null) {
            $this->notifications->scheduleRecalculationRequired(
                schoolId: $scheduleRule->school_id,
                impact: $result['impact'],
                cause: 'eliminação de uma regra',
                actor: $request->user(),
            );
            $response['impact'] = $result['impact'];
        }

        return response()->json($response);
    }
}
