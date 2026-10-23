<?php

namespace App\Http\Controllers\API\Schedules;

use App\Http\Controllers\Controller;
use App\Http\Requests\Schedules\GenerateScheduleRequest;
use App\Http\Requests\Schedules\StoreScheduleRequest;
use App\Http\Requests\Schedules\UpdateScheduleRequest;
use App\Http\Resources\Schedules\ScheduleResource;
use App\Models\Assistants\Assistant;
use App\Models\Assistants\AssistantTemporaryAssignments;
use App\Models\Schedules\Schedule;
use App\Services\ScheduleGenerationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class ScheduleController extends Controller
{
    public function __construct(
        private readonly ScheduleGenerationService $generationService,
    ) {}

    /**
     * Lista as escalas (filtradas por escola, datas, assistente ou estado).
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $user = $request->user();
        $schoolId = $request->attributes->get('currentSchoolId') ?? $request->header('X-School-ID');

        $query = Schedule::query()
            ->with([
                'assistant.user',
                'scheduleEntries.activityType',
                'scheduleEntries.scheduleRule',
                'scheduleEntries.replacedAssistant.user',
            ]);

        if ($schoolId) {
            $query->where('school_id', $schoolId);
        }

        // Limita a listagem às escalas do próprio assistente caso não seja administrador.
        if (! $user->isAdmin()) {
            $query->whereHas('assistant', function ($q) use ($user) {
                $q->where('user_id', $user->id);
            });
        }

        if ($request->filled('assistant_id')) {
            $query->where('assistant_id', $request->query('assistant_id'));
        }

        if ($request->filled('status')) {
            $query->where('status', $request->query('status'));
        }

        if ($request->filled('start_date') && $request->filled('end_date')) {
            $query->whereDate('week_start', '<=', $request->query('end_date'))
                ->whereDate('week_end', '>=', $request->query('start_date'));
        } elseif ($request->filled('week_start')) {
            $query->whereDate('week_start', $request->query('week_start'));
        }

        return ScheduleResource::collection(
            $query->orderBy('week_start')->paginate((int) $request->query('per_page', 50))
        );
    }

    /**
     * Executa o motor de geração automática de escalas para uma escola e intervalo de datas.
     */
    public function generate(GenerateScheduleRequest $request): JsonResponse
    {
        $schoolId = (string) ($request->attributes->get('currentSchoolId') ?? $request->header('X-School-ID'));
        $validated = $request->validated();

        // Se o administrador aprovou designações de mobilidade inter-escolar (transferências),
        // registar os AssistantTemporaryAssignments antes de recalcular a escala.
        if (! empty($validated['transfers']) && is_array($validated['transfers'])) {
            $userId = $request->user()?->id;
            foreach ($validated['transfers'] as $tr) {
                $extAssistant = Assistant::find($tr['assistant_id'] ?? null);
                if (! $extAssistant || (string) $extAssistant->school_id === $schoolId) {
                    continue;
                }

                AssistantTemporaryAssignments::firstOrCreate(
                    [
                        'assistant_id' => $extAssistant->id,
                        'origin_school_id' => $extAssistant->school_id,
                        'destination_school_id' => $schoolId,
                        'valid_from' => $tr['date'],
                        'valid_until' => $tr['date'],
                    ],
                    [
                        'created_by_user_id' => $userId,
                        'validated_by_user_id' => $userId,
                        'notes' => $tr['notes'] ?? 'Designação de cobertura inter-escolar via geração de escala.',
                    ]
                );
            }
        }

        $result = $this->generationService->generate(
            schoolId: $schoolId,
            startDate: $validated['start_date'],
            endDate: $validated['end_date'],
            preserveLocked: (bool) ($validated['preserve_locked'] ?? true),
            idempotencyKey: $validated['idempotency_key'] ?? null,
        );

        return response()->json($result, 200);
    }

    /**
     * Publica as escalas em rascunho (draft -> published) de uma escola num intervalo de datas.
     */
    public function publish(Request $request): JsonResponse
    {
        $schoolId = (string) ($request->attributes->get('currentSchoolId') ?? $request->header('X-School-ID'));
        if ($schoolId === '') {
            return response()->json([
                'message' => 'O cabeçalho X-School-ID é obrigatório para publicar escalas.',
            ], 422);
        }

        $validated = $request->validate([
            'start_date' => ['required', 'date_format:Y-m-d'],
            'end_date' => ['required', 'date_format:Y-m-d', 'after_or_equal:start_date'],
        ]);

        $count = $this->generationService->publish(
            schoolId: $schoolId,
            startDate: $validated['start_date'],
            endDate: $validated['end_date'],
        );

        return response()->json([
            'status' => 'published',
            'published_schedules_count' => $count,
        ]);
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(StoreScheduleRequest $request)
    {
        //
    }

    /**
     * Display the specified resource.
     */
    public function show(Schedule $schedule): ScheduleResource
    {
        $schedule->load([
            'assistant.user',
            'scheduleEntries.activityType',
            'scheduleEntries.scheduleRule',
            'scheduleEntries.replacedAssistant.user',
        ]);

        return new ScheduleResource($schedule);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(UpdateScheduleRequest $request, Schedule $schedule)
    {
        //
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Schedule $schedule)
    {
        //
    }
}
