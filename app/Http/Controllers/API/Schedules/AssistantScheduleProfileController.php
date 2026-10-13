<?php

namespace App\Http\Controllers\API\Schedules;

use App\Http\Controllers\Controller;
use App\Http\Requests\Schedules\StoreAssistantScheduleProfileRequest;
use App\Http\Requests\Schedules\UpdateAssistantScheduleProfileRequest;
use App\Http\Resources\Schedules\AssistantScheduleProfileResource;
use App\Models\Schedules\AssistantScheduleProfile;
use App\Services\AssistantScheduleProfileService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class AssistantScheduleProfileController extends Controller
{
    public function __construct(
        private readonly AssistantScheduleProfileService $scheduleProfileService
    ) {}

    /**
     * Display a listing of the resource for an assistant.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $assistantId = $request->query('assistant_id');
        $query = AssistantScheduleProfile::with(['shifts.shiftDays']);

        if ($assistantId) {
            $query->where('assistant_id', $assistantId);
        }

        $profiles = $query->orderByDesc('valid_from')->get();

        return AssistantScheduleProfileResource::collection($profiles);
    }

    /**
     * Store a newly created schedule profile for an assistant.
     */
    public function store(StoreAssistantScheduleProfileRequest $request): JsonResponse
    {
        $profile = $this->scheduleProfileService->create($request->validated());

        return (new AssistantScheduleProfileResource($profile))
            ->response()
            ->setStatusCode(201);
    }

    /**
     * Display the specified resource.
     */
    public function show(AssistantScheduleProfile $assistantScheduleProfile): AssistantScheduleProfileResource
    {
        return new AssistantScheduleProfileResource($assistantScheduleProfile->load(['shifts.shiftDays']));
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(UpdateAssistantScheduleProfileRequest $request, AssistantScheduleProfile $assistantScheduleProfile): AssistantScheduleProfileResource
    {
        $profile = $this->scheduleProfileService->update($assistantScheduleProfile, $request->validated());

        return new AssistantScheduleProfileResource($profile);
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(AssistantScheduleProfile $assistantScheduleProfile): JsonResponse
    {
        $this->scheduleProfileService->delete($assistantScheduleProfile);

        return response()->json([
            'message' => 'Perfil de horário eliminado com sucesso.',
        ]);
    }
}
