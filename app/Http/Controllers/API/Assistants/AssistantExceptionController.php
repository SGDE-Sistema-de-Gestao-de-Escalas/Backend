<?php

namespace App\Http\Controllers\API\Assistants;

use App\Http\Controllers\Controller;
use App\Http\Requests\Assistants\StoreAssistantExceptionRequest;
use App\Http\Requests\Assistants\UpdateAssistantExceptionRequest;
use App\Http\Resources\Assistants\AssistantExceptionResource;
use App\Models\Assistants\AssistantException;
use App\Services\AssistantExceptionService;
use App\Services\ScheduleIntegrityService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;

class AssistantExceptionController extends Controller
{
    public function __construct(
        private readonly AssistantExceptionService $exceptionService,
        private readonly ScheduleIntegrityService $scheduleIntegrityService,
    ) {}

    /**
     * Display a listing of the resource.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        Gate::authorize('viewAny', AssistantException::class);

        $schoolId = $request->header('X-School-ID');
        $assistantId = $request->query('assistant_id');
        $user = $request->user();

        $query = AssistantException::query()
            ->with('assistant');

        // Se for assistente (não-admin), só lista as suas próprias exceções
        if ($user && ! $user->isAdmin()) {
            $assistant = $user->assistant;
            $query->where('assistant_id', $assistant?->id ?? '00000000-0000-0000-0000-000000000000');
        } else {
            $assistantIds = $request->query('assistant_ids');
            if ($assistantId) {
                $query->where('assistant_id', $assistantId);
            } elseif ($assistantIds) {
                $ids = is_array($assistantIds) ? $assistantIds : explode(',', (string) $assistantIds);
                $query->whereIn('assistant_id', $ids);
            } elseif ($schoolId) {
                $query->where(function ($q) use ($schoolId) {
                    $q->whereHas('assistant', fn ($aq) => $aq->where('school_id', $schoolId))
                      ->orWhereHas('assistant.assistantTemporaryAssignments', fn ($tq) => $tq->where('destination_school_id', $schoolId));
                });
            }
        }

        $exceptions = $query->orderByDesc('valid_from')->get();

        return AssistantExceptionResource::collection($exceptions);
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(StoreAssistantExceptionRequest $request): JsonResponse
    {
        Gate::authorize('create', AssistantException::class);

        $exception = $this->exceptionService->create($request->validated());
        $exception->loadMissing('assistant');

        $schoolId = $exception->assistant?->school_id
            ?? $request->attributes->get('school_id')
            ?? $request->header('X-School-ID');

        $impact = null;
        if ($schoolId && $exception->valid_from) {
            $impact = $this->scheduleIntegrityService->checkImpact(
                schoolId: (string) $schoolId,
                startDate: $exception->valid_from->format('Y-m-d'),
                endDate: $exception->valid_until?->format('Y-m-d'),
                eventType: 'exception',
                assistantId: (string) $exception->assistant_id,
            );
        }

        $futureCheck = $this->exceptionService->checkFutureSchedulesWarning($exception);

        return (new AssistantExceptionResource($exception))
            ->additional([
                'message' => 'Exceção criada com sucesso.',
                'warning' => $futureCheck['warning'],
                'impact' => $impact,
            ])
            ->response()
            ->setStatusCode(201);
    }

    /**
     * Display the specified resource.
     */
    public function show(AssistantException $assistantException): AssistantExceptionResource
    {
        Gate::authorize('view', $assistantException);

        return new AssistantExceptionResource($assistantException->load('assistant'));
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(UpdateAssistantExceptionRequest $request, AssistantException $assistantException): AssistantExceptionResource
    {
        Gate::authorize('update', $assistantException);

        $updated = $this->exceptionService->update($assistantException, $request->validated());
        $updated->loadMissing('assistant');

        $schoolId = $updated->assistant?->school_id
            ?? $request->attributes->get('school_id')
            ?? $request->header('X-School-ID');

        $impact = null;
        if ($schoolId && $updated->valid_from) {
            $impact = $this->scheduleIntegrityService->checkImpact(
                schoolId: (string) $schoolId,
                startDate: $updated->valid_from->format('Y-m-d'),
                endDate: $updated->valid_until?->format('Y-m-d'),
                eventType: 'exception',
                assistantId: (string) $updated->assistant_id,
            );
        }

        $futureCheck = $this->exceptionService->checkFutureSchedulesWarning($updated);

        return (new AssistantExceptionResource($updated))
            ->additional([
                'message' => 'Exceção atualizada com sucesso.',
                'warning' => $futureCheck['warning'],
                'impact' => $impact,
            ]);
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Request $request, AssistantException $assistantException): JsonResponse
    {
        Gate::authorize('delete', $assistantException);

        $assistantException->loadMissing('assistant');
        $schoolId = $assistantException->assistant?->school_id
            ?? $request->attributes->get('school_id')
            ?? $request->header('X-School-ID');

        $validFromStr = $assistantException->valid_from?->format('Y-m-d');
        $validUntilStr = $assistantException->valid_until?->format('Y-m-d');
        $assistantId = (string) $assistantException->assistant_id;
        $futureCheck = $this->exceptionService->checkFutureSchedulesWarning($assistantException);

        $this->exceptionService->delete($assistantException);

        $impact = null;
        if ($schoolId && $validFromStr) {
            $impact = $this->scheduleIntegrityService->checkImpact(
                schoolId: (string) $schoolId,
                startDate: $validFromStr,
                endDate: $validUntilStr,
                eventType: 'exception',
                assistantId: $assistantId,
            );
        }

        return response()->json([
            'message' => $futureCheck['warning']
                ? 'Exceção eliminada com sucesso. ' . $futureCheck['warning']
                : 'Exceção eliminada com sucesso.',
            'warning' => $futureCheck['warning'],
            'impact' => $impact,
        ]);
    }
}
