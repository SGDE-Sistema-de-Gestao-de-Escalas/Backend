<?php

namespace App\Http\Controllers\API\Schedules;

use App\Http\Controllers\Controller;
use App\Http\Requests\Schedules\ScheduleRuleRequest;
use App\Http\Requests\Schedules\StoreScheduleRuleRequest;
use App\Http\Requests\Schedules\UpdateScheduleRuleRequest;
use App\Http\Resources\ScheduleRuleResource;
use App\Models\Schedules\ScheduleRule;
use App\Services\ScheduleRuleService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class ScheduleRuleController extends Controller
{
    public function __construct(private readonly ScheduleRuleService $service) {}

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
        $scheduleRule->delete();

        return response()->json(['message' => 'Regra removida com sucesso.']);
    }
}
