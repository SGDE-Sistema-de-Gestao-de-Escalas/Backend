<?php

namespace App\Http\Controllers\API\Dashboard;

use App\Http\Controllers\Controller;
use App\Http\Resources\AbsenceResource;
use App\Http\Resources\ActivityTypeResource;
use App\Http\Resources\AssistantResource;
use App\Http\Resources\HolidayResource;
use App\Http\Resources\ScheduleRuleResource;
use App\Http\Resources\Schedules\ScheduleResource;
use App\Http\Resources\SchoolOperatingExceptionResource;
use App\Http\Resources\SchoolOperatingRuleResource;
use App\Models\Absences\Absence;
use App\Models\Assistants\Assistant;
use App\Models\Assistants\AssistantTemporaryAssignments;
use App\Models\Schedules\Holiday;
use App\Models\Schedules\Schedule;
use App\Models\Schedules\ScheduleRule;
use App\Models\Schools\SchoolOperatingException;
use App\Models\Schools\SchoolOperatingRule;
use App\Models\System\ActivityType;
use App\Services\OperatingHoursResolver;
use App\Services\ScheduleIntegrityService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DashboardBootstrapController extends Controller
{
    public function __construct(
        private readonly OperatingHoursResolver $operatingHoursResolver,
        private readonly ScheduleIntegrityService $scheduleIntegrityService
    ) {}

    /**
     * Endpoint consolidado de arranque do Dashboard.
     * Devolve todos os dados essenciais (assistentes, escalas, ausências, calendário, regras e feriados)
     * num único pedido HTTP ultra-rápido, eliminando a cascata N+1 e filas de espera no servidor.
     */
    public function index(Request $request): JsonResponse
    {
        $schoolId = (string) (
            $request->attributes->get('school_id')
            ?? $request->attributes->get('currentSchoolId')
            ?? $request->header('X-School-ID')
            ?? $request->query('school_id')
            ?? ''
        );

        $startDate = $request->query('start_date');
        $endDate = $request->query('end_date');

        // 1. Assistentes (com utilizador, perfis contratuais e exceções)
        $assistantsQuery = Assistant::withTrashed()
            ->with([
                'user',
                'school',
                'assistantScheduleProfiles.shifts.shiftDays',
                'assistantExceptions',
            ])
            ->when($schoolId !== '', fn ($q) => $q->where('school_id', $schoolId));

        $countsQuery = clone $assistantsQuery;
        $assistantCounts = [
            'all' => (clone $countsQuery)->count(),
            'active' => (clone $countsQuery)
                ->whereNull('assistants.deleted_at')
                ->where(fn ($q) => $q->where('is_anonymized', false)->orWhereNull('is_anonymized'))
                ->whereHas('user', fn ($uq) => $uq->whereNull('deleted_at')->where('is_active', true))
                ->count(),
            'inactive' => (clone $countsQuery)
                ->where(function ($q) {
                    $q->whereNotNull('assistants.deleted_at')
                        ->orWhere('is_anonymized', true)
                        ->orWhereDoesntHave('user', fn ($uq) => $uq->whereNull('deleted_at')->where('is_active', true));
                })
                ->count(),
        ];

        $assistants = $assistantsQuery
            ->whereNull('assistants.deleted_at')
            ->where(fn ($q) => $q->where('is_anonymized', false)->orWhereNull('is_anonymized'))
            ->whereHas('user', fn ($uq) => $uq->whereNull('deleted_at')->where('is_active', true))
            ->get();

        // 2. Escalas (janela de datas solicitada)
        $schedules = Schedule::query()
            ->with([
                'assistant.user',
                'assistant.school',
                'scheduleEntries.activityType',
                'scheduleEntries.scheduleRule',
                'scheduleEntries.replacedAssistant.user',
            ])
            ->when($schoolId !== '', fn ($q) => $q->where('school_id', $schoolId))
            ->when($startDate && $endDate, fn ($q) => $q->whereDate('week_start', '<=', $endDate)->whereDate('week_end', '>=', $startDate))
            ->orderBy('week_start')
            ->limit(200)
            ->get();

        // 3. Ausências
        $absencesBaseQuery = Absence::query()
            ->when($schoolId !== '', fn ($q) => $q->whereHas('assistant', fn ($aq) => $aq->where('school_id', $schoolId)));

        $absenceCounts = [
            'all' => (clone $absencesBaseQuery)->count(),
            'pending' => (clone $absencesBaseQuery)->where('status', 'pending')->count(),
            'justified' => (clone $absencesBaseQuery)->where('status', 'justified')->count(),
            'unjustified' => (clone $absencesBaseQuery)->where('status', 'unjustified')->count(),
        ];

        $absencesQuery = (clone $absencesBaseQuery)
            ->with(['assistant.user', 'absenceType', 'createdByUser', 'justifiedByUser']);

        if ($startDate) {
            $absencesQuery->where(function ($q) use ($startDate) {
                $q->whereDate('valid_until', '>=', $startDate)
                    ->orWhere(function ($sub) use ($startDate) {
                        $sub->whereNull('valid_until')->whereDate('valid_from', '>=', $startDate);
                    });
            });
        }
        if ($endDate) {
            $absencesQuery->whereDate('valid_from', '<=', $endDate);
        }

        $absences = $absencesQuery->orderBy('valid_from', 'desc')->limit(200)->get();

        // 4. Calendário de Funcionamento
        $calendar = ($schoolId !== '' && $startDate && $endDate)
            ? $this->operatingHoursResolver->forRange($schoolId, Carbon::parse($startDate), Carbon::parse($endDate))
            : [];

        // 5. Regras de Horário de Funcionamento e Exceções
        $operatingRules = $schoolId !== ''
            ? SchoolOperatingRule::where('school_id', $schoolId)->orderByDesc('valid_from')->get()
            : collect();

        $operatingExceptions = $schoolId !== ''
            ? SchoolOperatingException::where('school_id', $schoolId)->orderByDesc('valid_from')->get()
            : collect();

        // 6. Regras de Cobertura da Escola (Schedule Rules)
        $scheduleRules = $schoolId !== ''
            ? ScheduleRule::where('school_id', $schoolId)->with('assistants.user')->orderBy('valid_from')->orderBy('id')->get()
            : collect();

        // 7. Dados Globais Estáticos (Tipos de Atividade e Feriados)
        $activityTypes = ActivityType::orderBy('name')->get();
        $holidays = Holiday::orderBy('date')->get();

        // 8. Transferências Temporárias
        $tempAssignments = AssistantTemporaryAssignments::query()
            ->with(['assistant.user', 'originSchool', 'destinationSchool'])
            ->when($schoolId !== '', function ($q) use ($schoolId) {
                $q->where(fn ($sub) => $sub->where('origin_school_id', $schoolId)->orWhere('destination_school_id', $schoolId));
            })
            ->when($startDate && $endDate, function ($q) use ($startDate, $endDate) {
                $q->whereDate('valid_from', '<=', $endDate)
                    ->where(fn ($sub) => $sub->whereNull('valid_until')->orWhereDate('valid_until', '>=', $startDate));
            })
            ->get();

        // 9. Recálculos Pendentes
        $pendingRecalculations = $schoolId !== ''
            ? $this->scheduleIntegrityService->getPendingRecalculations($schoolId)
            : ['has_pending' => false, 'affected_dates' => [], 'conflicts' => []];

        return response()->json([
            'data' => [
                'assistants' => [
                    'data' => AssistantResource::collection($assistants),
                    'counts' => $assistantCounts,
                ],
                'schedules' => ScheduleResource::collection($schedules),
                'absences' => [
                    'data' => AbsenceResource::collection($absences),
                    'counts' => $absenceCounts,
                ],
                'operating_calendar' => $calendar,
                'operating_rules' => SchoolOperatingRuleResource::collection($operatingRules),
                'operating_exceptions' => SchoolOperatingExceptionResource::collection($operatingExceptions),
                'schedule_rules' => ScheduleRuleResource::collection($scheduleRules),
                'activity_types' => ActivityTypeResource::collection($activityTypes),
                'holidays' => HolidayResource::collection($holidays),
                'temporary_assignments' => $tempAssignments,
                'pending_recalculations' => $pendingRecalculations,
            ],
        ]);
    }
}
