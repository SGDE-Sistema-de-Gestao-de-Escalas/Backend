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
use Illuminate\Support\Facades\DB;
use App\Services\ScheduleIntegrityService;
use Illuminate\Http\JsonResponse;
use Carbon\CarbonImmutable;
use App\Models\Schedules\ScheduleEntry;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class ScheduleController extends Controller
{
    public function __construct(
        private readonly ScheduleGenerationService $generationService,
        private readonly ScheduleIntegrityService $integrityService,
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
                'school',
                'assistant.school',
                'scheduleEntries.activityType',
                'scheduleEntries.scheduleRule',
                'scheduleEntries.replacedAssistant.user',
            ]);

        // Para administradores, filtra pela escola ativa do cabeçalho/contexto se fornecida.
        // Para assistentes (staff), devolve as suas escalas mesmo que geradas noutra escola (ex: transferência temporária).
        if ($user->isAdmin()) {
            if ($schoolId) {
                $query->where('school_id', $schoolId);
            }
        } else {
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
     * Devolve dias com escalas geradas que têm quebra de integridade/regras e requerem recálculo.
     */
    public function pendingRecalculations(Request $request): JsonResponse
    {
        $schoolId = (string) (
            $request->attributes->get('school_id')
            ?? $request->attributes->get('currentSchoolId')
            ?? $request->header('X-School-ID')
            ?? $request->user()?->school_id
            ?? $request->user()?->assistant?->school_id
        );

        if ($schoolId === '') {
            return response()->json([
                'has_pending' => false,
                'affected_dates' => [],
                'conflicts' => [],
                'summary' => 'Nenhuma escola selecionada.',
            ]);
        }

        $result = $this->integrityService->getPendingRecalculations($schoolId);

        return response()->json($result);
    }

    /**
     * Executa o motor de geração automática de escalas para uma escola e intervalo de datas.
     */
    public function generate(GenerateScheduleRequest $request): JsonResponse
    {
        $schoolId = (string) ($request->attributes->get('currentSchoolId') ?? $request->header('X-School-ID'));
        $validated = $request->validated();

        // Assegurar previamente que a atividade base/fallback existe para a escola
        $this->generationService->ensureFallbackActivity($schoolId);

        $result = DB::transaction(function () use ($request, $schoolId, $validated) {
            // Se o administrador aprovou designações de mobilidade inter-escolar (transferências),
            // registar os AssistantTemporaryAssignments atomicamente junto com a geração da escala.
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

            return $this->generationService->generate(
                schoolId: $schoolId,
                startDate: $validated['start_date'],
                endDate: $validated['end_date'],
                preserveLocked: (bool) ($validated['preserve_locked'] ?? true),
                idempotencyKey: $validated['idempotency_key'] ?? null,
            );
        });

        return response()->json($result, 200);
    }

    /**
     * Guarda manualmente alterações ou criação de escalas diárias na base de dados.
     * POST /api/schedules/save-days
     */
    public function saveDays(Request $request): JsonResponse
    {
        $schoolId = (string) ($request->attributes->get('currentSchoolId') ?? $request->header('X-School-ID'));
        if ($schoolId === '') {
            return response()->json([
                'message' => 'O cabeçalho X-School-ID é obrigatório para guardar escalas.',
            ], 422);
        }

        $validated = $request->validate([
            'days' => ['required', 'array'],
            'days.*.date' => ['required', 'date_format:Y-m-d'],
            'days.*.entries' => ['present', 'array'],
            'days.*.entries.*.assistant_id' => ['required'],
            'days.*.entries.*.activity_type_id' => ['nullable'],
            'days.*.entries.*.activity_name' => ['nullable', 'string', 'max:150'],
            'days.*.entries.*.schedule_rule_id' => ['nullable'],
            'days.*.entries.*.start_time' => ['required', 'string'],
            'days.*.entries.*.end_time' => ['required', 'string'],
            'days.*.entries.*.is_locked' => ['nullable', 'boolean'],
        ]);

        $now = now();
        $savedDaysCount = 0;

        foreach ($validated['days'] as $dayData) {
            $date = $dayData['date'];
            $carbonDate = CarbonImmutable::parse($date);
            $weekStart = $carbonDate->startOfWeek()->toDateString();
            $weekEnd = $carbonDate->endOfWeek()->toDateString();

            // Agrupar entradas por assistente
            $entriesByAssistant = [];
            foreach ($dayData['entries'] as $entry) {
                $aid = (string) $entry['assistant_id'];
                $entriesByAssistant[$aid][] = $entry;
            }

            // Identificar todos os assistentes relevantes
            $existingScheduleIds = Schedule::query()
                ->where('school_id', $schoolId)
                ->where('week_start', $weekStart)
                ->pluck('id', 'assistant_id')
                ->all();

            $allAssistantIds = array_unique(array_merge(
                array_keys($entriesByAssistant),
                array_keys($existingScheduleIds)
            ));

            foreach ($allAssistantIds as $assistantId) {
                $schedule = Schedule::firstOrCreate(
                    [
                        'school_id' => $schoolId,
                        'assistant_id' => $assistantId,
                        'week_start' => $weekStart,
                        'week_end' => $weekEnd,
                    ],
                    [
                        'status' => 'draft',
                        'generated_at' => $now,
                    ]
                );

                // Se o horário estava publicado e foram guardadas alterações manuais, reverter para rascunho
                if ($schedule->status !== 'draft') {
                    $schedule->status = 'draft';
                    $schedule->save();
                }

                // Remover entradas antigas desta data para este assistente
                ScheduleEntry::where('schedule_id', $schedule->id)
                    ->where('date', $date)
                    ->delete();

                // Inserir as novas entradas
                if (! empty($entriesByAssistant[$assistantId])) {
                    foreach ($entriesByAssistant[$assistantId] as $entry) {
                        ScheduleEntry::create([
                            'schedule_id' => $schedule->id,
                            'activity_type_id' => $entry['activity_type_id'] ?? null,
                            'activity_name' => $entry['activity_name'] ?? 'Atividade',
                            'schedule_rule_id' => $entry['schedule_rule_id'] ?? null,
                            'date' => $date,
                            'start_time' => substr($entry['start_time'], 0, 5),
                            'end_time' => substr($entry['end_time'], 0, 5),
                            'is_locked' => (bool) ($entry['is_locked'] ?? false),
                        ]);
                    }
                }
            }

            $savedDaysCount++;
        }

        return response()->json([
            'message' => 'Escalas guardadas com sucesso na base de dados.',
            'saved_days' => $savedDaysCount,
        ], 200);
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
            'start_date' => ['required', 'date_format:Y-m-d', 'after_or_equal:today'],
            'end_date' => ['required', 'date_format:Y-m-d', 'after_or_equal:start_date'],
        ], [
            'start_date.after_or_equal' => 'Não é permitido publicar escalas de dias passados.',
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
            'school',
                'assistant.school',
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
