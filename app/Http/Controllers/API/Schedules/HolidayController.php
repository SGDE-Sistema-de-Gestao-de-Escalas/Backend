<?php

namespace App\Http\Controllers\API\Schedules;

use App\Http\Controllers\Controller;
use App\Http\Requests\Schedules\StoreHolidayRequest;
use App\Http\Requests\Schedules\UpdateHolidayRequest;
use App\Http\Resources\Schedules\HolidayResource;
use App\Models\Auth\User;
use App\Models\Schedules\Holiday;
use App\Models\Schedules\ScheduleEntry;
use App\Services\Schedules\HolidayImportService;
use App\Services\System\NotificationService;
use App\Services\Schedules\ScheduleIntegrityService;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;

class HolidayController extends Controller
{
    public function __construct(
        private readonly HolidayImportService $holidayImportService,
        private readonly ScheduleIntegrityService $scheduleIntegrityService,
        private readonly NotificationService $notificationService
    ) {}

    /**
     * Listar feriados com filtros opcionais.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        Gate::authorize('viewAny', Holiday::class);

        $filters = $request->validate([
            'year' => ['sometimes', 'integer', 'between:1,9999'],
            'month' => ['sometimes', 'integer', 'between:1,12'],
            'per_page' => ['sometimes', 'integer', 'between:1,100'],
            'page' => ['sometimes', 'integer', 'min:1'],
        ]);

        $query = Holiday::query()->orderBy('date');

        if (isset($filters['year'])) {
            $query->whereYear('date', $filters['year']);
        }

        if (isset($filters['month'])) {
            $query->whereMonth('date', $filters['month']);
        }

        return HolidayResource::collection(
            $query->paginate($filters['per_page'] ?? 15)->withQueryString()
        );
    }

    /**
     * Lista dos 308 concelhos portugueses para seleção.
     */
    public function municipalities(): JsonResponse
    {
        Gate::authorize('viewAny', Holiday::class);

        return response()->json([
            'data' => $this->holidayImportService->getMunicipalities(),
        ]);
    }

    /**
     * Pré-visualizar feriados nacionais e municipais a importar para um determinado ano.
     */
    public function preview(Request $request): JsonResponse
    {
        Gate::authorize('create', Holiday::class);

        $validated = $request->validate([
            'year' => ['required', 'integer', 'between:2020,2050'],
            'municipality' => ['nullable', 'string', 'max:100'],
            'include_carnival' => ['sometimes'],
        ]);

        $preview = $this->holidayImportService->preview(
            year: (int) $validated['year'],
            municipalitySlug: $validated['municipality'] ?? null,
            includeCarnival: $request->boolean('include_carnival', true)
        );

        return response()->json([
            'data' => $preview,
        ]);
    }

    /**
     * Importar feriados nacionais e municipais em lote.
     */
    public function import(Request $request): JsonResponse
    {
        Gate::authorize('create', Holiday::class);

        $validated = $request->validate([
            'year' => ['required', 'integer', 'between:2020,2050'],
            'municipality' => ['nullable', 'string', 'max:100'],
            'dates' => ['nullable', 'array'],
            'dates.*' => ['string', 'date_format:Y-m-d'],
            'include_carnival' => ['sometimes'],
        ]);

        $result = $this->holidayImportService->import(
            year: (int) $validated['year'],
            municipalitySlug: $validated['municipality'] ?? null,
            selectedDates: $validated['dates'] ?? null,
            includeCarnival: $request->boolean('include_carnival', true),
            actor: $request->user()
        );

        $imported = $result['imported_count'];
        $skipped = $result['skipped_count'];

        $msg = "{$imported} feriado(s) importado(s) com sucesso.";
        if ($skipped > 0) {
            $msg .= " ({$skipped} já existia(m) e foram ignorados).";
        }

        return response()->json([
            'message' => $msg,
            'imported_count' => $imported,
            'skipped_count' => $skipped,
            'total_processed' => $result['total_processed'],
            'requires_recalculation' => $result['requires_recalculation'],
            'impacted_schools' => $result['impacted_schools'],
        ]);
    }

    /**
     * Criar feriado manual.
     */
    public function store(StoreHolidayRequest $request): JsonResponse
    {
        $validated = $request->validated();
        $today = CarbonImmutable::today()->toDateString();

        if ($validated['date'] < $today) {
            return response()->json([
                'message' => 'Não é possível registar um feriado numa data que já passou.',
            ], 422);
        }

        $holiday = Holiday::create($validated);

        // Verificar impacto em escalas
        $impact = $this->evaluateAndNotifyHolidayImpact([$validated['date']], $request->user());

        $additionalData = ['message' => 'Feriado criado com sucesso.'];
        if ($impact) {
            $additionalData['impact'] = $impact;
        }

        return (new HolidayResource($holiday))
            ->additional($additionalData)
            ->response()
            ->setStatusCode(201);
    }

    /**
     * Exibir feriado.
     */
    public function show(Holiday $holiday): HolidayResource
    {
        Gate::authorize('view', $holiday);

        return new HolidayResource($holiday);
    }

    /**
     * Atualizar feriado.
     */
    public function update(UpdateHolidayRequest $request, Holiday $holiday): JsonResponse
    {
        $today = CarbonImmutable::today()->toDateString();
        $oldDateStr = $this->toDateString($holiday->date);

        // Não permitir alterar feriado que já passou
        if ($oldDateStr < $today) {
            return response()->json([
                'message' => 'Não é possível alterar um feriado que já passou.',
            ], 422);
        }

        $validated = $request->validated();
        if (isset($validated['date']) && $validated['date'] < $today) {
            return response()->json([
                'message' => 'A nova data do feriado não pode ser uma data passada.',
            ], 422);
        }

        $holiday->update($validated);
        $newDateStr = $this->toDateString($holiday->date);

        // Avaliar impacto em escalas nas datas envolvidas
        $impact = $this->evaluateAndNotifyHolidayImpact(
            array_unique([$oldDateStr, $newDateStr]),
            $request->user()
        );

        $resource = new HolidayResource($holiday);
        $additional = ['message' => 'Feriado atualizado com sucesso.'];
        if ($impact) {
            $additional['impact'] = $impact;
        }

        return $resource->additional($additional)->response();
    }

    /**
     * Eliminar feriado.
     */
    public function destroy(Holiday $holiday, Request $request): JsonResponse
    {
        Gate::authorize('delete', $holiday);

        $today = CarbonImmutable::today()->toDateString();
        $dateStr = $this->toDateString($holiday->date);

        // Não permitir eliminar feriado que já passou
        if ($dateStr < $today) {
            return response()->json([
                'message' => 'Não é possível eliminar um feriado que já passou.',
            ], 422);
        }

        $holiday->delete();

        // Avaliar impacto em escalas para a data do feriado removido
        $impact = $this->evaluateAndNotifyHolidayImpact([$dateStr], $request->user());

        $response = ['message' => 'Feriado removido com sucesso.'];
        if ($impact) {
            $response['impact'] = $impact;
        }

        return response()->json($response);
    }

    /**
     * Avalia o impacto de alterações em feriados sobre escalas geradas e notifica administradores.
     *
     * @param  array<string>  $dates
     * @return array<string, mixed>|null
     */
    private function evaluateAndNotifyHolidayImpact(array $dates, ?User $actor = null): ?array
    {
        $today = CarbonImmutable::today()->toDateString();
        $futureDates = array_values(array_filter($dates, fn ($d) => $d >= $today));

        if (empty($futureDates)) {
            return null;
        }

        $schoolIds = ScheduleEntry::query()
            ->whereIn('date', $futureDates)
            ->whereHas('schedule')
            ->join('schedules', 'schedule_entries.schedule_id', '=', 'schedules.id')
            ->distinct()
            ->pluck('schedules.school_id')
            ->all();

        if (empty($schoolIds)) {
            return null;
        }

        $minDate = min($futureDates);
        $maxDate = max($futureDates);
        $impacts = [];
        $requiresRecalc = false;

        foreach ($schoolIds as $schoolId) {
            $impact = $this->scheduleIntegrityService->checkImpact(
                schoolId: (string) $schoolId,
                startDate: $minDate,
                endDate: $maxDate,
                eventType: 'holiday'
            );

            if (! empty($impact['requires_recalculation'])) {
                $requiresRecalc = true;
                $this->notificationService->scheduleRecalculationRequired(
                    schoolId: (string) $schoolId,
                    impact: $impact,
                    assistantName: null,
                    cause: 'feriado',
                    actor: $actor
                );
                $impacts[] = [
                    'school_id' => $schoolId,
                    'impact' => $impact,
                ];
            }
        }

        return [
            'has_impact' => ! empty($impacts),
            'requires_recalculation' => $requiresRecalc,
            'impacted_schools' => $impacts,
            'affected_dates' => $futureDates,
            'message' => $requiresRecalc
                ? 'A alteração ao feriado afeta escalas geradas em estabelecimento(s). É necessário recalcular o horário.'
                : 'Sem conflitos detetados com escalas futuras.',
        ];
    }

    private function toDateString(mixed $date): string
    {
        if ($date instanceof CarbonImmutable || $date instanceof \DateTimeInterface) {
            return $date->format('Y-m-d');
        }

        return substr((string) $date, 0, 10);
    }
}
