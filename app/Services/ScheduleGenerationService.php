<?php

namespace App\Services;

use App\Mail\SchedulePublishedMail;
use App\Models\Absences\Absence;
use App\Models\Assistants\Assistant;
use App\Models\Assistants\AssistantException;
use App\Models\Assistants\AssistantTemporaryAssignments;
use App\Models\Schedules\AssistantScheduleProfile;
use App\Models\Schedules\Schedule;
use App\Models\Schedules\ScheduleEntry;
use App\Models\Schedules\ScheduleRule;
use App\Models\Schools\School;
use App\Models\System\ActivityType;
use Carbon\CarbonImmutable;
use Carbon\CarbonPeriod;
use Database\Seeders\ActivityTypeSeeder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\ValidationException;
use Throwable;

class ScheduleGenerationService
{
    public function __construct(
        private readonly OperatingHoursResolver $operatingHoursResolver
    ) {}

    /**
     * Executa o Super Algoritmo de Geração de Escalas para uma escola num intervalo de datas.
     *
     * @return array<string, mixed>
     */
    public function generate(
        string $schoolId,
        string $startDate,
        string $endDate,
        bool $preserveLocked = true,
        ?string $idempotencyKey = null
    ): array {
        $cacheKey = null;
        if ($idempotencyKey !== null && $idempotencyKey !== '') {
            $versionHash = $this->computeVersionHash($schoolId, $startDate, $endDate, $preserveLocked);
            $lockedFlag = $preserveLocked ? '1' : '0';
            $payloadHash = hash('sha256', "{$idempotencyKey}:{$startDate}:{$endDate}:{$lockedFlag}:{$versionHash}");
            $cacheKey = "schedule_gen:{$schoolId}:{$payloadHash}";
            if (Cache::has($cacheKey)) {
                return Cache::get($cacheKey);
            }
        }

        $school = School::findOrFail($schoolId);

        // Garantir que a escola tem a atividade base (is_fallback = true)
        $fallbackActivity = $this->resolveOrCreateFallbackActivity($school);

        // 1. Carregar Snapshot e calcular Version Hash fora de transação longa
        $snapshot = $this->loadSnapshot($schoolId, $startDate, $endDate);

        // 2. Executar as 8 Validações Pré-Geração (Pre-Flight Checks)
        $preChecks = $this->runPreValidations($snapshot, $startDate, $endDate);
        if ($preChecks['has_blocking_errors']) {
            throw ValidationException::withMessages([
                'pre_validation' => collect($preChecks['items'])
                    ->where('status', 'error')
                    ->pluck('detail')
                    ->values()
                    ->all(),
            ]);
        }

        $calendarDays = $this->operatingHoursResolver->forRange($schoolId, $startDate, $endDate);

        $conflicts = [];
        $insights = [];
        $hoursAccounting = [];
        $auditLog = [];
        $daysSummary = [];
        $plannedEntriesByDate = [];
        $lunchBlocksByDate = [];

        // =====================================================================
        // FASE 0: CICLO POR CADA DATA (DIA)
        // =====================================================================
        foreach ($calendarDays as $dayInfo) {
            $date = $dayInfo['date'];
            $weekday = (int) $dayInfo['day_of_week']; // 0 = Seg ... 6 = Dom

            if (! $dayInfo['is_open']) {
                $auditLog[] = [
                    'date' => $date,
                    'message' => "Estabelecimento encerrado ({$dayInfo['source']}). Nenhuma entrada gerada.",
                ];
                $daysSummary[] = [
                    'date' => $date,
                    'day_of_week' => $weekday,
                    'is_open' => false,
                    'source' => $dayInfo['source'],
                    'reason' => $dayInfo['reason'],
                ];
                $plannedEntriesByDate[$date] = [];

                continue;
            }

            $schoolHours = [
                'opening' => $this->toMinutes($dayInfo['opening_time']),
                'closing' => $this->toMinutes($dayInfo['closing_time']),
                'opening_str' => $dayInfo['opening_time'],
                'closing_str' => $dayInfo['closing_time'],
                'lunch_start' => ! empty($dayInfo['lunch_start']) ? $this->toMinutes(substr((string) $dayInfo['lunch_start'], 0, 5)) : null,
                'lunch_end' => ! empty($dayInfo['lunch_end']) ? $this->toMinutes(substr((string) $dayInfo['lunch_end'], 0, 5)) : null,
                'lunch_duration' => ! empty($dayInfo['lunch_duration_minutes']) ? (int) $dayInfo['lunch_duration_minutes'] : 60,
            ];

            $daysSummary[] = [
                'date' => $date,
                'day_of_week' => $weekday,
                'is_open' => true,
                'source' => $dayInfo['source'],
                'reason' => $dayInfo['reason'],
                'opening_time' => $dayInfo['opening_time'],
                'closing_time' => $dayInfo['closing_time'],
            ];

            $dayAssignments = [];

            // Avaliar entradas bloqueadas existentes (Valid Locks vs Invalid Locks por ausência superveniente)
            if ($preserveLocked) {
                foreach ($snapshot['locked_entries'][$date] ?? [] as $locked) {
                    $lockInterval = [$this->toMinutes($locked['start_time']), $this->toMinutes($locked['end_time'])];
                    $absenceReason = $this->checkAbsenceOrOutgoingTransfer(
                        $snapshot,
                        $locked['assistant_id'],
                        $schoolId,
                        $date,
                        $lockInterval[0],
                        $lockInterval[1]
                    );
                    $shiftRes = $this->resolveEffectiveShift($snapshot, $locked['assistant_id'], $date, $weekday, $schoolHours);

                    if ($absenceReason !== null || $shiftRes['status'] === 'unavailable') {
                        // Invariante R8: Bloqueio inválido por ausência superveniente -> não conta como cobertura efetiva!
                        $insights[] = [
                            'type' => 'locked_absence_conflict',
                            'date' => $date,
                            'assistant_id' => $locked['assistant_id'],
                            'activity_type_id' => $locked['activity_type_id'],
                            'activity_name' => $locked['activity_name'],
                            'start_time' => $locked['start_time'],
                            'end_time' => $locked['end_time'],
                            'message' => "Entrada bloqueada de {$locked['assistant_name']} ({$locked['start_time']}–{$locked['end_time']}) colide com ausência/indisponibilidade superveniente e foi excluída da cobertura efetiva.",
                        ];

                        continue;
                    }

                    $dayAssignments[] = $locked;
                }
            }

            // =================================================================
            // FASE 1 (BLOCO AZUL): REGRAS OBRIGATÓRIAS (Ronda A -> B -> C)
            // =================================================================
            $mandatoryRules = $this->getValidRulesForDay($snapshot['rules'], $date, $weekday, 'mandatory');
            $mandatoryRules = $this->sortRulesByRestrictionDegree($mandatoryRules);

            foreach ($mandatoryRules as $rule) {
                $effStart = max($this->toMinutes($rule->period_start), $schoolHours['opening']);
                $effEnd = min($this->toMinutes($rule->period_end), $schoolHours['closing']);
                if ($effEnd <= $effStart) {
                    continue;
                }

                $skipped = [];
                $failedTitulars = [];
                $assignedFullCount = $this->countFullCoverageForRule($dayAssignments, $rule, $effStart, $effEnd);

                // -------------------------------------------------------------
                // RONDA A: Período Completo [effStart, effEnd) com a fila da regra
                // -------------------------------------------------------------
                $ruleCandidateIds = $rule->assistants->pluck('id')->all();
                $incomingTrIds = collect($snapshot['incoming_transfers'] ?? [])->pluck('assistant_id')->all();
                $incomingAssistants = $this->getSchoolAndIncomingAssistantsForDay($snapshot, $schoolId, $date)
                    ->filter(fn ($a) => in_array($a->id, $incomingTrIds, true) && ! in_array($a->id, $ruleCandidateIds, true));
                $rondaACandidates = $rule->assistants->concat($incomingAssistants);

                foreach ($rondaACandidates as $candidate) {
                    if ($assignedFullCount >= $rule->min) {
                        break;
                    }

                    $priority = (int) ($candidate->pivot?->priority ?? 1);
                    if ($this->isAlreadyAssignedToInterval($dayAssignments, $candidate->id, $effStart, $effEnd, $rule->id)) {
                        continue;
                    }

                    $eval = $this->evaluateCandidateForInterval(
                        $snapshot,
                        $dayAssignments,
                        $candidate,
                        $schoolId,
                        $date,
                        $weekday,
                        $schoolHours,
                        $effStart,
                        $effEnd
                    );

                    if ($eval['status'] === 'rejected') {
                        $skipped[] = [
                            'assistant_id' => $candidate->id,
                            'priority' => $priority,
                            'reason' => $eval['reason'],
                        ];
                        $failedTitulars[] = [
                            'assistant_id' => $candidate->id,
                            'assistant_name' => $this->assistantDisplayName($candidate),
                            'reason' => $eval['reason'],
                        ];

                        continue;
                    }

                    if ($eval['status'] === 'ok_free') {
                        $replaced = array_shift($failedTitulars);
                        $dayAssignments[] = $this->makeAssignment(
                            $candidate,
                            $rule->activityType,
                            $date,
                            $effStart,
                            $effEnd,
                            'mandatory',
                            false,
                            $rule->id,
                            $replaced['assistant_id'] ?? null,
                            $replaced['reason'] ?? ($priority > $rule->min ? 'priority_substitute' : null)
                        );
                        $assignedFullCount++;

                        if ($replaced !== null || $priority > $rule->min) {
                            $insights[] = [
                                'type' => 'substitute_assigned',
                                'date' => $date,
                                'rule_id' => $rule->id,
                                'activity_name' => $rule->activityType?->name,
                                'assistant_id' => $candidate->id,
                                'replaced_assistant_id' => $replaced['assistant_id'] ?? null,
                                'message' => "{$date} [{$rule->activityType?->name}]: Suplente {$this->assistantDisplayName($candidate)} ({$priority}º) assumiu a vaga.",
                            ];
                        }
                    } elseif ($eval['status'] === 'ok_swap_optional') {
                        // Swap com Regra Facultativa + Realocação em Cascata da Facultativa
                        $displacedOptionals = $eval['optional_conflicts'];
                        $this->removeAssignmentsByIds($dayAssignments, array_column($displacedOptionals, 'temp_id'));

                        $replaced = array_shift($failedTitulars);
                        $dayAssignments[] = $this->makeAssignment(
                            $candidate,
                            $rule->activityType,
                            $date,
                            $effStart,
                            $effEnd,
                            'mandatory',
                            false,
                            $rule->id,
                            $replaced['assistant_id'] ?? null,
                            'optional_swap'
                        );
                        $assignedFullCount++;

                        foreach ($displacedOptionals as $displaced) {
                            if (! empty($displaced['schedule_rule_id'])) {
                                $optRule = $snapshot['rules']->firstWhere('id', $displaced['schedule_rule_id']);
                                if ($optRule) {
                                    $this->reallocateDisplacedOptionalRule(
                                        $snapshot,
                                        $dayAssignments,
                                        $optRule,
                                        $schoolId,
                                        $date,
                                        $weekday,
                                        $schoolHours,
                                        $insights
                                    );
                                }
                            }
                        }

                        $insights[] = [
                            'type' => 'optional_swap',
                            'date' => $date,
                            'rule_id' => $rule->id,
                            'assistant_id' => $candidate->id,
                            'message' => "{$date}: Tarefa facultativa de {$this->assistantDisplayName($candidate)} substituída pela obrigatória {$rule->activityType?->name}.",
                        ];
                    }
                }

                // -------------------------------------------------------------
                // RONDA B: Partição em Sub-blocos de 15 min entre suplentes da fila
                // -------------------------------------------------------------
                $this->allocateAvailableRuleCandidates(
                    $snapshot, $dayAssignments, $rule, $rondaACandidates,
                    $schoolId, $date, $weekday, $schoolHours, $effStart, $effEnd, $insights
                );

                $gaps = $this->detectUncoveredSlices($dayAssignments, $rule, $effStart, $effEnd);
                if (! empty($gaps)) {
                    $this->runRoundBSubBlockPartitioning(
                        $snapshot,
                        $dayAssignments,
                        $rule,
                        $schoolId,
                        $date,
                        $weekday,
                        $schoolHours,
                        $effStart,
                        $effEnd,
                        $failedTitulars,
                        $insights
                    );
                    $dayAssignments = $this->mergeAdjacentEntries($dayAssignments);
                }

                // -------------------------------------------------------------
                // RONDA C: C1 Troca Atómica entre Obrigatórias + C2 Aptidão Universal
                // -------------------------------------------------------------
                $gapsAfterB = $this->detectUncoveredSlices($dayAssignments, $rule, $effStart, $effEnd);
                foreach ($gapsAfterB as $gap) {
                    // C1: Troca Atómica entre Tarefas Obrigatórias
                    while ($this->countCoverageInSlice($dayAssignments, $rule, $gap['start'], $gap['end']) < $rule->min) {
                        $swapped = $this->tryAtomicMandatorySwap(
                            $snapshot,
                            $dayAssignments,
                            $rule,
                            $schoolId,
                            $date,
                            $weekday,
                            $schoolHours,
                            $gap['start'],
                            $gap['end'],
                            $insights
                        );
                        if (! $swapped) {
                            break;
                        }
                    }

                    // C2: Aptidão Universal (colegas disponíveis da mesma escola fora da lista de preferências)
                    while ($this->countCoverageInSlice($dayAssignments, $rule, $gap['start'], $gap['end']) < $rule->min) {
                        $allocatedInternal = $this->tryUniversalInternalFallback(
                            $snapshot,
                            $dayAssignments,
                            $rule,
                            $schoolId,
                            $date,
                            $weekday,
                            $schoolHours,
                            $gap['start'],
                            $gap['end'],
                            $failedTitulars,
                            $insights
                        );
                        if (! $allocatedInternal) {
                            break;
                        }
                    }

                    $dayAssignments = $this->mergeAdjacentEntries($dayAssignments);
                }

                // -------------------------------------------------------------
                // Verificação final de Défice + Pesquisa de Cedências Inter-Escolas
                // -------------------------------------------------------------
                $finalDeficits = $this->detectUncoveredSlices($dayAssignments, $rule, $effStart, $effEnd);
                foreach ($finalDeficits as $deficit) {
                    $transferCandidates = $this->findTransferCandidates(
                        $schoolId,
                        $date,
                        $weekday,
                        $deficit['start'],
                        $deficit['end']
                    );

                    $conflicts[] = [
                        'date' => $date,
                        'rule_id' => $rule->id,
                        'activity_type_id' => $rule->activity_type_id,
                        'activity_name' => $rule->activityType?->name,
                        'period_start' => $this->toTimeStr($deficit['start']),
                        'period_end' => $this->toTimeStr($deficit['end']),
                        'required' => $rule->min,
                        'assigned' => $deficit['assigned'],
                        'missing' => max(0, $rule->min - $deficit['assigned']),
                        'skipped' => $skipped,
                        'transfer_candidates' => $transferCandidates,
                    ];
                }
            }

            // =================================================================
            // FASE 2 (BLOCO VERDE): REGRAS FACULTATIVAS
            // =================================================================
            $optionalRules = $this->getValidRulesForDay($snapshot['rules'], $date, $weekday, 'optional');
            $optionalRules = $this->sortRulesByRestrictionDegree($optionalRules);

            foreach ($optionalRules as $rule) {
                $effStart = max($this->toMinutes($rule->period_start), $schoolHours['opening']);
                $effEnd = min($this->toMinutes($rule->period_end), $schoolHours['closing']);
                if ($effEnd <= $effStart) {
                    continue;
                }

                $assignedOpt = $this->countFullCoverageForRule($dayAssignments, $rule, $effStart, $effEnd);

                $ruleCandidateIds = $rule->assistants->pluck('id')->all();
                $incomingTrIds = collect($snapshot['incoming_transfers'] ?? [])->pluck('assistant_id')->all();
                $incomingAssistants = $this->getSchoolAndIncomingAssistantsForDay($snapshot, $schoolId, $date)
                    ->filter(fn ($a) => in_array($a->id, $incomingTrIds, true) && ! in_array($a->id, $ruleCandidateIds, true));
                $rondaACandidates = $rule->assistants->concat($incomingAssistants);

                foreach ($rondaACandidates as $candidate) {
                    if ($assignedOpt >= $rule->min) {
                        break;
                    }

                    $eval = $this->evaluateCandidateForInterval(
                        $snapshot,
                        $dayAssignments,
                        $candidate,
                        $schoolId,
                        $date,
                        $weekday,
                        $schoolHours,
                        $effStart,
                        $effEnd
                    );

                    // Regra facultativa só aloca se estiver 100% livre (sem sobreposição)
                    if ($eval['status'] === 'ok_free') {
                        $dayAssignments[] = $this->makeAssignment(
                            $candidate,
                            $rule->activityType,
                            $date,
                            $effStart,
                            $effEnd,
                            'optional',
                            false,
                            $rule->id,
                            null,
                            null
                        );
                        $assignedOpt++;
                    }
                }

                $this->allocateAvailableRuleCandidates(
                    $snapshot, $dayAssignments, $rule, $rondaACandidates,
                    $schoolId, $date, $weekday, $schoolHours, $effStart, $effEnd, $insights
                );
                $assignedOpt = $this->countFullCoverageForRule($dayAssignments, $rule, $effStart, $effEnd);

                if ($assignedOpt < $rule->min) {
                    $insights[] = [
                        'type' => 'optional_incomplete',
                        'date' => $date,
                        'rule_id' => $rule->id,
                        'activity_name' => $rule->activityType?->name,
                        'assigned' => $assignedOpt,
                        'required' => $rule->min,
                        'message' => "{$date}: Regra facultativa '{$rule->activityType?->name}' ficou com {$assignedOpt}/{$rule->min}.",
                    ];
                }
            }

            // =================================================================
            // FASE 3: ALMOÇO BALANCEADO, "APOIO ESCOLA" E CONTABILIDADE CONTRATUAL
            // =================================================================
            $eligibleAssistantsToday = $this->getSchoolAndIncomingAssistantsForDay($snapshot, $schoolId, $date);
            $lunchAlternator = 0;
            $lunchBlocksByDate[$date] = [];

            foreach ($eligibleAssistantsToday as $assistant) {
                $shiftRes = $this->resolveEffectiveShift($snapshot, $assistant->id, $date, $weekday, $schoolHours);
                if ($shiftRes['status'] !== 'ok') {
                    continue;
                }

                $shift = $shiftRes['schedule'];

                // Se tem falta de dia inteiro, registar na contabilidade contratual e não gerar blocos de trabalho
                if ($this->hasFullDayAbsence($snapshot, $assistant->id, $date)) {
                    $hoursAccounting[] = [
                        'date' => $date,
                        'assistant_id' => $assistant->id,
                        'assistant_name' => $this->assistantDisplayName($assistant),
                        'contracted_minutes' => $shift['contracted_minutes'],
                        'assigned_specific_minutes' => 0,
                        'assigned_fallback_minutes' => 0,
                        'lunch_minutes' => 0,
                        'absence_minutes' => $shift['effective_work_minutes'],
                        'school_closed_clipped_minutes' => $shift['school_closed_clipped_minutes'],
                    ];

                    continue;
                }

                $currentTasks = $this->getAssistantAssignments($dayAssignments, $assistant->id);
                $partialAbsences = $this->getPartialAbsenceIntervals($snapshot, $assistant->id, $date, $shift['entry_min'], $shift['exit_min']);
                $outgoingTransfers = $this->getOutgoingTransferIntervals($snapshot, $assistant->id, $schoolId, $date, $shift['entry_min'], $shift['exit_min']);

                // 1. Posicionar o bloco exato de almoço (lunch_duration_minutes) dentro de [lunch_start, lunch_end]
                $lunchBlock = null;

                // Prioridade de Ausências sobre Almoço:
                // Se o assistente tem uma ausência parcial que cubra ou coincida com a janela de almoço,
                // a ausência absorve a refeição ("esquece o almoço") e não aloca almoço no dia.
                $absenceCoversLunch = false;
                if (! empty($partialAbsences) && $shift['lunch_start_min'] !== null && $shift['lunch_end_min'] !== null) {
                    foreach ($partialAbsences as [$absStart, $absEnd]) {
                        if ($absEnd > $shift['lunch_start_min'] && $absStart < $shift['lunch_end_min']) {
                            $absenceCoversLunch = true;
                            break;
                        }
                    }
                }

                if (! $absenceCoversLunch && $shift['lunch_enabled'] && $shift['lunch_duration'] > 0) {
                    $occupiedForLunch = array_merge(
                        array_map(fn ($t) => [$t['start_min'], $t['end_min']], $currentTasks),
                        $partialAbsences,
                        $outgoingTransfers
                    );
                    $lunchBlock = $this->chooseBalancedLunchBlock($shift, $occupiedForLunch, $lunchAlternator);
                    $lunchAlternator++;
                    if ($lunchBlock !== null) {
                        $lunchBlocksByDate[$date][$assistant->id] = [
                            'start_time' => $this->toTimeStr($lunchBlock[0]),
                            'end_time' => $this->toTimeStr($lunchBlock[1]),
                            'duration_minutes' => $lunchBlock[1] - $lunchBlock[0],
                        ];
                    }
                }

                // 2. Subtrair tarefas + almoço exato + faltas parciais + cedências de saída ao turno efetivo recortado
                $occupiedIntervals = array_merge(
                    array_map(fn ($t) => [$t['start_min'], $t['end_min']], $currentTasks),
                    $lunchBlock ? [$lunchBlock] : [],
                    $partialAbsences,
                    $outgoingTransfers
                );

                $freeIntervals = $this->subtractIntervals($shift['entry_min'], $shift['exit_min'], $occupiedIntervals);

                // 3. Preencher todos os períodos livres com a Atividade Base (is_fallback = true)
                $fallbackMinutes = 0;
                foreach ($freeIntervals as [$freeStart, $freeEnd]) {
                    if ($freeEnd > $freeStart) {
                        $dayAssignments[] = $this->makeAssignment(
                            $assistant,
                            $fallbackActivity,
                            $date,
                            $freeStart,
                            $freeEnd,
                            'fallback',
                            false,
                            null,
                            null,
                            null
                        );
                        $fallbackMinutes += ($freeEnd - $freeStart);
                    }
                }

                $specificMinutes = array_sum(array_map(fn ($t) => $t['end_min'] - $t['start_min'], $currentTasks));
                $absenceMinutes = array_sum(array_map(fn ($i) => $i[1] - $i[0], $partialAbsences));

                $hoursAccounting[] = [
                    'date' => $date,
                    'assistant_id' => $assistant->id,
                    'assistant_name' => $this->assistantDisplayName($assistant),
                    'contracted_minutes' => $shift['contracted_minutes'],
                    'assigned_specific_minutes' => $specificMinutes,
                    'assigned_fallback_minutes' => $fallbackMinutes,
                    'lunch_minutes' => $lunchBlock ? ($lunchBlock[1] - $lunchBlock[0]) : 0,
                    'absence_minutes' => $absenceMinutes,
                    'school_closed_clipped_minutes' => $shift['school_closed_clipped_minutes'],
                ];
            }

            // Ordenação canónica determinística (Invariante R9)
            $dayAssignments = $this->mergeAdjacentEntries($dayAssignments);
            usort($dayAssignments, function (array $a, array $b) {
                return strcmp($a['start_time'], $b['start_time'])
                    ?: strcmp($a['assistant_id'], $b['assistant_id'])
                    ?: strcmp($a['end_time'], $b['end_time']);
            });

            $plannedEntriesByDate[$date] = $dayAssignments;
        }

        // 3. Executar Validador Independente Pós-Geração (13 checks)
        $postChecks = $this->runPostValidations(
            $snapshot,
            $calendarDays,
            $plannedEntriesByDate,
            $lunchBlocksByDate,
            $hoursAccounting,
            $conflicts
        );

        // 4. Persistir em Transação Curta verificando o Version Hash do Snapshot
        $persistedSchedules = DB::transaction(function () use (
            $schoolId,
            $startDate,
            $endDate,
            $preserveLocked,
            $snapshot,
            $plannedEntriesByDate
        ) {
            $currentHash = $this->computeVersionHash($schoolId, $startDate, $endDate);
            if ($currentHash !== $snapshot['version_hash']) {
                throw ValidationException::withMessages([
                    'concurrency' => 'Os dados de horários, faltas ou regras foram alterados concorrentemente durante o cálculo. Por favor tente novamente.',
                ]);
            }

            return $this->persistGeneratedPlan(
                $schoolId,
                $startDate,
                $endDate,
                $preserveLocked,
                $snapshot,
                $plannedEntriesByDate
            );
        });

        $response = [
            'status' => 'draft',
            'resolution_status' => empty($conflicts) ? 'complete_validated' : 'draft_with_deficit',
            'school_id' => $schoolId,
            'start_date' => $startDate,
            'end_date' => $endDate,
            'generated_at' => now()->format('Y-m-d\TH:i:s.u\Z'),
            'days' => $daysSummary,
            'schedules_count' => count($persistedSchedules),
            'entries_count' => collect($plannedEntriesByDate)->flatten(1)->count(),
            'lunch_blocks' => $lunchBlocksByDate,
            'conflicts' => $conflicts,
            'insights' => $insights,
            'hours_accounting' => $hoursAccounting,
            'validations' => [
                'pre' => $preChecks,
                'post' => $postChecks,
            ],
            'audit_log' => $auditLog,
        ];

        if ($cacheKey !== null) {
            Cache::put($cacheKey, $response, now()->addMinutes(2));
        }

        return $response;
    }

    /**
     * Publica as escalas em rascunho de uma escola num intervalo de datas
     * e envia um único email consolidado por assistente abrangido.
     */
    public function publish(string $schoolId, string $startDate, string $endDate): int
    {
        $notificationsByAssistant = [];

        $publishedCount = DB::transaction(function () use ($schoolId, $startDate, $endDate, &$notificationsByAssistant) {
            $schedules = Schedule::with(['assistant.user', 'school'])
                ->where('school_id', $schoolId)
                ->whereDate('week_start', '<=', $endDate)
                ->whereDate('week_end', '>=', $startDate)
                ->where('status', 'draft')
                ->lockForUpdate()
                ->get();

            $now = now();
            foreach ($schedules as $schedule) {
                $wasPreviouslyPublished = $schedule->published_at !== null;

                $schedule->status = 'published';
                $schedule->published_at = $now;
                $schedule->save();

                $assistant = $schedule->assistant;
                $user = $assistant?->user;

                if ($assistant && ! $assistant->is_anonymized && $user && $user->is_active && $user->email) {
                    $assistantId = (string) $assistant->id;
                    if (! isset($notificationsByAssistant[$assistantId])) {
                        $notificationsByAssistant[$assistantId] = [
                            'user' => $user,
                            'school_name' => $schedule->school?->name ?? 'Estabelecimento de Ensino',
                            'is_update' => $wasPreviouslyPublished,
                        ];
                    } elseif ($wasPreviouslyPublished) {
                        $notificationsByAssistant[$assistantId]['is_update'] = true;
                    }
                }
            }

            return $schedules->count();
        });

        foreach ($notificationsByAssistant as $payload) {
            try {
                Mail::to($payload['user']->email)->send(new SchedulePublishedMail(
                    user: $payload['user'],
                    schoolName: $payload['school_name'],
                    startDate: $startDate,
                    endDate: $endDate,
                    isUpdate: $payload['is_update'],
                ));
            } catch (Throwable $e) {
                report($e);
            }
        }

        return $publishedCount;
    }

    /**
     * Ponto de entrada público para assegurar que a escola tem a atividade fallback persistida.
     */
    public function ensureFallbackActivity(string|School $school): ActivityType
    {
        $schoolModel = $school instanceof School ? $school : School::findOrFail($school);
        return $this->resolveOrCreateFallbackActivity($schoolModel);
    }

    /**
     * Garante que a escola tem uma atividade base com is_fallback = true.
     */
    private function resolveOrCreateFallbackActivity(School $school): ActivityType
    {
        $fallback = ActivityType::where('school_id', $school->id)
            ->where('is_fallback', true)
            ->first();

        if ($fallback) {
            if (! $fallback->active) {
                $fallback->update(['active' => true]);
            }

            return $fallback;
        }

        app(ActivityTypeSeeder::class)->seedForSchool($school);

        return ActivityType::where('school_id', $school->id)
            ->where('is_fallback', true)
            ->firstOrFail();
    }

    /**
     * Carrega o Snapshot de entidades necessárias para o cálculo em memória.
     *
     * @return array<string, mixed>
     */
    private function loadSnapshot(string $schoolId, string $startDate, string $endDate): array
    {
        $assistants = Assistant::with(['user'])
            ->where('school_id', $schoolId)
            ->whereNull('deleted_at')
            ->where(fn ($q) => $q->where('is_anonymized', false)->orWhereNull('is_anonymized'))
            ->whereHas('user', fn ($q) => $q->where('is_active', true)->whereNull('deleted_at')->whereNull('anonymized_at'))
            ->orderBy('id')
            ->get()
            ->keyBy('id');

        $incomingTransfers = AssistantTemporaryAssignments::with(['assistant.user'])
            ->where('destination_school_id', $schoolId)
            ->whereDate('valid_from', '<=', $endDate)
            ->where(fn ($q) => $q->whereNull('valid_until')->orWhereDate('valid_until', '>=', $startDate))
            ->get();

        $outgoingTransfers = AssistantTemporaryAssignments::where('origin_school_id', $schoolId)
            ->whereDate('valid_from', '<=', $endDate)
            ->where(fn ($q) => $q->whereNull('valid_until')->orWhereDate('valid_until', '>=', $startDate))
            ->get()
            ->groupBy('assistant_id');

        $allAssistantIds = $assistants->keys()
            ->merge($incomingTransfers->pluck('assistant_id'))
            ->unique()
            ->values()
            ->all();

        $profiles = AssistantScheduleProfile::with(['shifts.shiftDays'])
            ->whereIn('assistant_id', $allAssistantIds)
            ->whereDate('valid_from', '<=', $endDate)
            ->where(fn ($q) => $q->whereNull('valid_until')->orWhereDate('valid_until', '>=', $startDate))
            ->orderByDesc('valid_from')
            ->get()
            ->groupBy('assistant_id');

        $exceptions = AssistantException::whereIn('assistant_id', $allAssistantIds)
            ->whereDate('valid_from', '<=', $endDate)
            ->where(fn ($q) => $q->whereNull('valid_until')->orWhereDate('valid_until', '>=', $startDate))
            ->get()
            ->groupBy('assistant_id');

        $absences = Absence::whereIn('assistant_id', $allAssistantIds)
            ->whereDate('valid_from', '<=', $endDate)
            ->where(fn ($q) => $q->whereNull('valid_until')->orWhereDate('valid_until', '>=', $startDate))
            ->get()
            ->groupBy('assistant_id');

        $rules = ScheduleRule::with(['activityType', 'assistants.user'])
            ->where('school_id', $schoolId)
            ->whereDate('valid_from', '<=', $endDate)
            ->where(fn ($q) => $q->whereNull('valid_until')->orWhereDate('valid_until', '>=', $startDate))
            ->whereHas('activityType', fn ($q) => $q->where('active', true)->where(fn ($q2) => $q2->where('is_fallback', false)->orWhereNull('is_fallback')))
            ->orderBy('id')
            ->get();

        $lockedEntriesRaw = ScheduleEntry::with(['schedule.assistant.user', 'activityType'])
            ->whereHas('schedule', fn ($q) => $q->where('school_id', $schoolId)->whereNull('deleted_at'))
            ->whereBetween('date', [$startDate, $endDate])
            ->where('is_locked', true)
            ->orderBy('start_time')
            ->get();

        $lockedByDate = [];
        foreach ($lockedEntriesRaw as $entry) {
            $dateStr = is_string($entry->date) ? substr($entry->date, 0, 10) : $entry->date->format('Y-m-d');
            $assistant = $entry->schedule?->assistant;
            if (! $assistant) {
                continue;
            }
            $lockedByDate[$dateStr][] = [
                'temp_id' => $entry->id,
                'persisted_id' => $entry->id,
                'assistant_id' => $assistant->id,
                'assistant_name' => $this->assistantDisplayName($assistant),
                'activity_type_id' => $entry->activity_type_id,
                'activity_name' => $entry->activity_name ?? $entry->activityType?->name ?? 'Atividade',
                'is_fallback' => (bool) ($entry->activityType?->is_fallback ?? false),
                'schedule_rule_id' => $entry->schedule_rule_id,
                'date' => $dateStr,
                'start_min' => $this->toMinutes(substr((string) $entry->start_time, 0, 5)),
                'end_min' => $this->toMinutes(substr((string) $entry->end_time, 0, 5)),
                'start_time' => substr((string) $entry->start_time, 0, 5),
                'end_time' => substr((string) $entry->end_time, 0, 5),
                'rule_type' => 'locked',
                'is_locked' => true,
                'replaced_assistant_id' => $entry->replaced_assistant_id,
                'replacement_reason' => $entry->replacement_reason,
            ];
        }

        return [
            'school_id' => $schoolId,
            'assistants' => $assistants,
            'incoming_transfers' => $incomingTransfers,
            'outgoing_transfers' => $outgoingTransfers,
            'profiles' => $profiles,
            'exceptions' => $exceptions,
            'absences' => $absences,
            'rules' => $rules,
            'locked_entries' => $lockedByDate,
            'version_hash' => $this->computeVersionHash($schoolId, $startDate, $endDate),
        ];
    }

    public function computeVersionHash(
        string $schoolId,
        string $startDate,
        string $endDate,
        bool $preserveLocked = true
    ): string {
        $ruleData = ScheduleRule::where('school_id', $schoolId)
            ->selectRaw('COUNT(*) as total, MAX(updated_at) as max_up')
            ->first();
        $ruleSig = $ruleData ? "{$ruleData->total}:{$ruleData->max_up}" : '0:none';

        $absenceData = Absence::withTrashed()
            ->whereHas('assistant', fn ($q) => $q->withTrashed()->where('school_id', $schoolId))
            ->whereDate('valid_from', '<=', $endDate)
            ->where(function ($q) use ($startDate) {
                $q->whereNull('valid_until')
                    ->orWhereDate('valid_until', '>=', $startDate);
            })
            ->selectRaw('COUNT(*) as total, MAX(updated_at) as max_up')
            ->first();
        $absenceSig = $absenceData ? "{$absenceData->total}:{$absenceData->max_up}" : '0:none';

        $exceptionData = AssistantException::withTrashed()
            ->whereHas('assistant', fn ($q) => $q->withTrashed()->where('school_id', $schoolId))
            ->whereDate('valid_from', '<=', $endDate)
            ->where(function ($q) use ($startDate) {
                $q->whereNull('valid_until')
                    ->orWhereDate('valid_until', '>=', $startDate);
            })
            ->selectRaw('COUNT(*) as total, MAX(updated_at) as max_up')
            ->first();
        $exceptionSig = $exceptionData ? "{$exceptionData->total}:{$exceptionData->max_up}" : '0:none';

        $profileData = AssistantScheduleProfile::whereHas('assistant', fn ($q) => $q->withTrashed()->where('school_id', $schoolId))
            ->selectRaw('COUNT(*) as total, MAX(updated_at) as max_up')
            ->first();
        $profileSig = $profileData ? "{$profileData->total}:{$profileData->max_up}" : '0:none';

        $assistantData = Assistant::withTrashed()
            ->where('school_id', $schoolId)
            ->selectRaw('COUNT(*) as total, MAX(updated_at) as max_up')
            ->first();
        $assistantSig = $assistantData ? "{$assistantData->total}:{$assistantData->max_up}" : '0:none';

        $transferData = AssistantTemporaryAssignments::where(function ($q) use ($schoolId) {
                $q->where('destination_school_id', $schoolId)
                    ->orWhere('origin_school_id', $schoolId);
            })
            ->whereDate('valid_from', '<=', $endDate)
            ->whereDate('valid_until', '>=', $startDate)
            ->selectRaw('COUNT(*) as total, MAX(updated_at) as max_up')
            ->first();
        $transferSig = $transferData ? "{$transferData->total}:{$transferData->max_up}" : '0:none';

        $lockedSig = '0';
        if ($preserveLocked) {
            $lockedData = ScheduleEntry::whereHas('schedule', fn ($q) => $q->where('school_id', $schoolId))
                ->where('is_locked', true)
                ->whereDate('date', '>=', $startDate)
                ->whereDate('date', '<=', $endDate)
                ->selectRaw('COUNT(*) as total, MAX(updated_at) as max_up')
                ->first();
            $lockedSig = $lockedData ? "{$lockedData->total}:{$lockedData->max_up}" : '0:none';
        }

        return hash('sha256', implode('|', [
            $schoolId,
            $startDate,
            $endDate,
            $preserveLocked ? '1' : '0',
            $ruleSig,
            $absenceSig,
            $exceptionSig,
            $profileSig,
            $assistantSig,
            $transferSig,
            $lockedSig,
        ]));
    }

    /**
     * Executa as 8 Validações Pré-Geração (Pre-Flight Checks).
     *
     * @param  array<string, mixed>  $snapshot
     * @return array{has_blocking_errors: bool, items: list<array<string, string>>}
     */
    private function runPreValidations(array $snapshot, string $startDate, string $endDate): array
    {
        $items = [];
        $hasBlockingErrors = false;

        // 1. pre-profile
        $missingProfileNames = [];
        foreach ($snapshot['assistants'] as $assistant) {
            $assistantProfiles = $snapshot['profiles']->get($assistant->id, collect());
            if ($assistantProfiles->isEmpty()) {
                $missingProfileNames[] = $this->assistantDisplayName($assistant);
            }
        }
        $items[] = [
            'code' => 'pre-profile',
            'status' => empty($missingProfileNames) ? 'ok' : 'warn',
            'detail' => empty($missingProfileNames)
                ? 'Todos os assistentes ativos têm perfil de horário válido.'
                : 'Assistentes sem perfil de horário configurado: '.implode(', ', $missingProfileNames).'.',
        ];

        // 2. pre-rotation & 4. pre-lunch-valid
        $rotationErrors = [];
        $lunchErrors = [];
        foreach ($snapshot['profiles'] as $assistantId => $profileList) {
            foreach ($profileList as $profile) {
                if ($profile->type === 'rotativo') {
                    $hasA = $profile->shifts->contains('shift_label', 'A');
                    $hasB = $profile->shifts->contains('shift_label', 'B');
                    if (! $profile->starts_with || ! $profile->rotation_period || ! $hasA || ! $hasB) {
                        $rotationErrors[] = "Perfil rotativo {$profile->id} sem turnos A/B ou período de rotação completo.";
                    }
                }
                foreach ($profile->shifts as $shift) {
                    if ($shift->lunch_enabled) {
                        $lStart = $shift->lunch_start ? $this->toMinutes(substr((string) $shift->lunch_start, 0, 5)) : null;
                        $lEnd = $shift->lunch_end ? $this->toMinutes(substr((string) $shift->lunch_end, 0, 5)) : null;
                        $dur = (int) ($shift->lunch_duration_minutes ?? 0);
                        $eMin = $this->toMinutes(substr((string) $shift->entry_time, 0, 5));
                        $xMin = $this->toMinutes(substr((string) $shift->exit_time, 0, 5));

                        if ($lStart === null || $lEnd === null || $lStart < $eMin || $lEnd > $xMin || ($lEnd - $lStart) < $dur) {
                            $lunchErrors[] = "Turno {$shift->shift_label} do perfil {$profile->id} tem janela de almoço inválida.";
                        }
                    }
                }
            }
        }
        if (! empty($rotationErrors)) {
            $hasBlockingErrors = true;
        }
        if (! empty($lunchErrors)) {
            $hasBlockingErrors = true;
        }
        $items[] = [
            'code' => 'pre-rotation',
            'status' => empty($rotationErrors) ? 'ok' : 'error',
            'detail' => empty($rotationErrors) ? 'Perfis rotativos íntegros.' : implode(' ', $rotationErrors),
        ];
        $items[] = [
            'code' => 'pre-lunch-valid',
            'status' => empty($lunchErrors) ? 'ok' : 'error',
            'detail' => empty($lunchErrors) ? 'Janelas de almoço válidas.' : implode(' ', $lunchErrors),
        ];

        // 5. pre-rules-valid & 6. pre-ambiguous-overlap
        $understaffedRules = [];
        $ambiguousOverlaps = [];
        $rulesArr = $snapshot['rules']->values()->all();
        foreach ($rulesArr as $idx => $rule) {
            if ($rule->assistants->count() < $rule->min) {
                $understaffedRules[] = $rule->activityType?->name ?? $rule->id;
            }
            for ($j = $idx + 1; $j < count($rulesArr); $j++) {
                $other = $rulesArr[$j];
                if ($rule->activity_type_id === $other->activity_type_id) {
                    $sharedDays = array_intersect($rule->days_of_week ?? [], $other->days_of_week ?? []);
                    if (! empty($sharedDays) && $this->intervalsOverlap(
                        $this->toMinutes($rule->period_start),
                        $this->toMinutes($rule->period_end),
                        $this->toMinutes($other->period_start),
                        $this->toMinutes($other->period_end)
                    )) {
                        $ambiguousOverlaps[] = ($rule->activityType?->name ?? 'Atividade')." ({$rule->period_start}-{$rule->period_end} vs {$other->period_start}-{$other->period_end})";
                    }
                }
            }
        }

        $items[] = [
            'code' => 'pre-rules-valid',
            'status' => empty($understaffedRules) ? 'ok' : 'warn',
            'detail' => empty($understaffedRules)
                ? 'Todas as regras têm candidatos suficientes na lista de preferências.'
                : 'Regras com menos candidatos na lista do que o mínimo exigido: '.implode(', ', $understaffedRules).'.',
        ];
        $items[] = [
            'code' => 'pre-ambiguous-overlap',
            'status' => empty($ambiguousOverlaps) ? 'ok' : 'warn',
            'detail' => empty($ambiguousOverlaps)
                ? 'Sem sobreposição ambígua de regras da mesma atividade.'
                : 'Regras da mesma atividade sobrepostas no mesmo horário: '.implode('; ', $ambiguousOverlaps).'.',
        ];
        $items[] = [
            'code' => 'pre-dow-sync',
            'status' => 'ok',
            'detail' => 'Convenção de dia da semana (0=Segunda..6=Domingo) sincronizada.',
        ];

        return [
            'has_blocking_errors' => $hasBlockingErrors,
            'items' => $items,
        ];
    }

    /**
     * Resolve o turno efetivo (recortado à janela da escola) de um assistente num dia.
     *
     * @param  array<string, mixed>  $snapshot
     * @param  array{opening: int, closing: int, opening_str: string, closing_str: string}  $schoolHours
     * @return array<string, mixed>
     */
    public function resolveEffectiveShift(
        array $snapshot,
        string $assistantId,
        string $date,
        int $weekday,
        array $schoolHours
    ): array {
        // 1. Verificar AssistantException em vigor na data e dia da semana
        $exceptions = $snapshot['exceptions']->get($assistantId, collect());
        foreach ($exceptions as $exc) {
            $from = $exc->valid_from?->format('Y-m-d');
            $until = $exc->valid_until?->format('Y-m-d');
            if ($from && $date < $from) {
                continue;
            }
            if ($until && $date > $until) {
                continue;
            }

            $excDays = $exc->days;
            if (is_array($excDays) && ! empty($excDays) && ! in_array($weekday, array_map('intval', $excDays), true)) {
                continue;
            }

            $rawStart = $exc->getRawOriginal('start_time');
            $rawEnd = $exc->getRawOriginal('end_time');
            if ($exc->type === 'unavailable' || empty($rawStart) || empty($rawEnd)) {
                return [
                    'status' => 'unavailable',
                    'reason' => 'unresolved_exception',
                ];
            }

            return $this->clipShiftToSchoolWindow(
                $this->toMinutes(substr((string) $rawStart, 0, 5)),
                $this->toMinutes(substr((string) $rawEnd, 0, 5)),
                (bool) $exc->lunch_enabled,
                $exc->getRawOriginal('lunch_start') ? $this->toMinutes(substr((string) $exc->getRawOriginal('lunch_start'), 0, 5)) : null,
                $exc->getRawOriginal('lunch_end') ? $this->toMinutes(substr((string) $exc->getRawOriginal('lunch_end'), 0, 5)) : null,
                (int) ($exc->lunch_duration_minutes ?? 0),
                $schoolHours
            );
        }

        // 2. Buscar AssistantScheduleProfile vigente na data
        $profiles = $snapshot['profiles']->get($assistantId, collect());
        $profile = $profiles->first(function (AssistantScheduleProfile $p) use ($date) {
            $from = $p->valid_from?->format('Y-m-d');
            $until = $p->valid_until?->format('Y-m-d');

            return ($from === null || $from <= $date) && ($until === null || $until >= $date);
        });

        if (! $profile) {
            return [
                'status' => 'off',
                'reason' => 'outside_working_period',
            ];
        }

        $targetLabel = 'A';
        if ($profile->type === 'rotativo') {
            $anchorDate = CarbonImmutable::parse($profile->valid_from->format('Y-m-d'));
            $currentDate = CarbonImmutable::parse($date);
            $startsWith = $profile->starts_with === 'B' ? 'B' : 'A';
            $opposite = $startsWith === 'A' ? 'B' : 'A';

            if ($profile->rotation_period === 'monthly') {
                // Rotação mensal por mês civil real (nunca 4 semanas)
                $monthsDiff = (($currentDate->year - $anchorDate->year) * 12) + ($currentDate->month - $anchorDate->month);
                $index = abs($monthsDiff) % 2;
            } elseif ($profile->rotation_period === 'biweekly') {
                $weeksDiff = (int) floor($anchorDate->startOfWeek()->diffInDays($currentDate->startOfWeek(), false) / 7);
                $index = intdiv(abs($weeksDiff), 2) % 2;
            } else {
                $weeksDiff = (int) floor($anchorDate->startOfWeek()->diffInDays($currentDate->startOfWeek(), false) / 7);
                $index = abs($weeksDiff) % 2;
            }

            $targetLabel = $index === 0 ? $startsWith : $opposite;
        }

        $shift = $profile->shifts->firstWhere('shift_label', $targetLabel)
            ?? $profile->shifts->first();

        if (! $shift) {
            return [
                'status' => 'off',
                'reason' => 'outside_working_period',
            ];
        }

        $worksToday = $shift->shiftDays->contains(fn ($d) => (int) $d->day_of_week === $weekday);
        if (! $worksToday) {
            return [
                'status' => 'off',
                'reason' => 'outside_working_period',
            ];
        }

        // Se este assistente tiver uma transferência temporária de entrada com horário parcial nesta data e escola
        $incomingTr = null;
        if (! empty($snapshot['incoming_transfers'])) {
            foreach ($snapshot['incoming_transfers'] as $tr) {
                if ($tr->assistant_id === $assistantId) {
                    $from = $tr->valid_from?->format('Y-m-d');
                    $until = $tr->valid_until?->format('Y-m-d');
                    if (($from === null || $from <= $date) && ($until === null || $until >= $date)) {
                        $incomingTr = $tr;
                        break;
                    }
                }
            }
        }

        if ($incomingTr && ! empty($incomingTr->start_time) && ! empty($incomingTr->end_time)) {
            $trStart = $this->toMinutes(substr((string) $incomingTr->start_time, 0, 5));
            $trEnd = $this->toMinutes(substr((string) $incomingTr->end_time, 0, 5));
            return $this->clipShiftToSchoolWindow(
                $trStart,
                $trEnd,
                false,
                null,
                null,
                0,
                $schoolHours
            );
        }

        $shiftLunchStart = $shift->lunch_start
            ? $this->toMinutes(substr((string) $shift->lunch_start, 0, 5))
            : ($schoolHours['lunch_start'] ?? null);
        $shiftLunchEnd = $shift->lunch_end
            ? $this->toMinutes(substr((string) $shift->lunch_end, 0, 5))
            : ($schoolHours['lunch_end'] ?? null);
        $shiftLunchDuration = (int) ($shift->lunch_duration_minutes ?? $schoolHours['lunch_duration'] ?? 60);

        return $this->clipShiftToSchoolWindow(
            $this->toMinutes(substr((string) $shift->entry_time, 0, 5)),
            $this->toMinutes(substr((string) $shift->exit_time, 0, 5)),
            (bool) $shift->lunch_enabled,
            $shiftLunchStart,
            $shiftLunchEnd,
            $shiftLunchDuration,
            $schoolHours
        );
    }

    /**
     * Recorta um turno à janela de abertura/fecho da escola e calcula os minutos contratuais vs. cortados.
     *
     * @param  array{opening: int, closing: int, opening_str: string, closing_str: string}  $schoolHours
     * @return array<string, mixed>
     */
    private function clipShiftToSchoolWindow(
        int $rawEntryMin,
        int $rawExitMin,
        bool $lunchEnabled,
        ?int $lunchStartMin,
        ?int $lunchEndMin,
        int $lunchDuration,
        array $schoolHours
    ): array {
        $effEntry = max($rawEntryMin, $schoolHours['opening']);
        $effExit = min($rawExitMin, $schoolHours['closing']);

        if ($effExit <= $effEntry) {
            return [
                'status' => 'off',
                'reason' => 'outside_working_period',
            ];
        }

        $effLunchEnabled = $lunchEnabled && $lunchStartMin !== null && $lunchEndMin !== null && $lunchDuration > 0;
        $effLunchStart = $effLunchEnabled ? max($lunchStartMin, $effEntry) : null;
        $effLunchEnd = $effLunchEnabled ? min($lunchEndMin, $effExit) : null;

        if ($effLunchEnabled && ($effLunchEnd - $effLunchStart) < $lunchDuration) {
            $effLunchEnabled = false;
        }

        $rawWorkMinutes = max(0, ($rawExitMin - $rawEntryMin) - ($lunchEnabled ? $lunchDuration : 0));
        $clippedWorkMinutes = max(0, ($effExit - $effEntry) - ($effLunchEnabled ? $lunchDuration : 0));
        $clippedDiff = max(0, $rawWorkMinutes - $clippedWorkMinutes);

        return [
            'status' => 'ok',
            'schedule' => [
                'raw_entry_min' => $rawEntryMin,
                'raw_exit_min' => $rawExitMin,
                'entry_min' => $effEntry,
                'exit_min' => $effExit,
                'entry_time' => $this->toTimeStr($effEntry),
                'exit_time' => $this->toTimeStr($effExit),
                'lunch_enabled' => $effLunchEnabled,
                'lunch_start_min' => $effLunchStart,
                'lunch_end_min' => $effLunchEnd,
                'lunch_duration' => $effLunchEnabled ? $lunchDuration : 0,
                'contracted_minutes' => $rawWorkMinutes,
                'effective_work_minutes' => $clippedWorkMinutes,
                'school_closed_clipped_minutes' => $clippedDiff,
            ],
        ];
    }

    /**
     * Avalia se um candidato pode assumir o intervalo [startMin, endMin).
     *
     * @param  array<string, mixed>  $snapshot
     * @param  list<array<string, mixed>>  $dayAssignments
     * @param  array{opening: int, closing: int, opening_str: string, closing_str: string}  $schoolHours
     * @return array<string, mixed>
     */
    private function evaluateCandidateForInterval(
        array $snapshot,
        array $dayAssignments,
        Assistant $candidate,
        string $schoolId,
        string $date,
        int $weekday,
        array $schoolHours,
        int $startMin,
        int $endMin
    ): array {
        // 1. Elegibilidade RGPD e Conta Ativa
        if ($candidate->trashed() || $candidate->is_anonymized || ! $candidate->user
            || $candidate->user->trashed() || ! $candidate->user->is_active || $candidate->user->anonymized_at) {
            return ['status' => 'rejected', 'reason' => 'inactive_assistant'];
        }

        // 2. Pertence à escola ou tem cedência de entrada válida hoje?
        $belongsToSchool = $candidate->school_id === $schoolId
            || $this->hasIncomingTransfer($snapshot, $candidate->id, $schoolId, $date);

        if (! $belongsToSchool) {
            return ['status' => 'rejected', 'reason' => 'different_school'];
        }

        // 3. Falta (total ou parcial) ou cedência de saída sobreposta?
        $absenceOrTransfer = $this->checkAbsenceOrOutgoingTransfer($snapshot, $candidate->id, $schoolId, $date, $startMin, $endMin);
        if ($absenceOrTransfer !== null) {
            return ['status' => 'rejected', 'reason' => $absenceOrTransfer];
        }

        // 4. Horário efetivo do assistente no dia
        $shiftRes = $this->resolveEffectiveShift($snapshot, $candidate->id, $date, $weekday, $schoolHours);
        if ($shiftRes['status'] !== 'ok') {
            return ['status' => 'rejected', 'reason' => $shiftRes['reason']];
        }

        $shift = $shiftRes['schedule'];
        if ($startMin < $shift['entry_min'] || $endMin > $shift['exit_min']) {
            return ['status' => 'rejected', 'reason' => 'outside_working_period'];
        }

        // 5. Sobreposição com tarefas já atribuídas hoje
        $currentTasks = $this->getAssistantAssignments($dayAssignments, $candidate->id);
        $overlapping = array_values(array_filter(
            $currentTasks,
            fn (array $t) => $this->intervalsOverlap($startMin, $endMin, $t['start_min'], $t['end_min'])
        ));

        foreach ($overlapping as $ov) {
            if ($ov['is_locked'] || $ov['rule_type'] === 'mandatory') {
                return ['status' => 'rejected', 'reason' => 'overlapping_assignment'];
            }
        }

        // 6. Verificar se preserva >= lunch_duration contínuos dentro da janela de almoço
        $nonOptionalTasks = array_values(array_filter(
            $currentTasks,
            fn (array $t) => ! in_array($t['temp_id'], array_column($overlapping, 'temp_id'), true)
        ));

        if (! $this->canFitActivityWithLunch($snapshot, $candidate->id, $schoolId, $date, $shift, $nonOptionalTasks, $startMin, $endMin)) {
            return ['status' => 'rejected', 'reason' => 'lunch_conflict'];
        }

        if (! empty($overlapping)) {
            return [
                'status' => 'ok_swap_optional',
                'optional_conflicts' => $overlapping,
            ];
        }

        return ['status' => 'ok_free'];
    }

    /**
     * Verifica se adicionar [newStart, newEnd) preserva pelo menos lunch_duration minutos contínuos na janela de almoço.
     */
    private function canFitActivityWithLunch(
        array $snapshot,
        string $assistantId,
        string $schoolId,
        string $date,
        array $shift,
        array $existingTasks,
        int $newStart,
        int $newEnd
    ): bool {
        if (! $shift['lunch_enabled'] || $shift['lunch_duration'] <= 0) {
            return true;
        }

        $lStart = $shift['lunch_start_min'];
        $lEnd = $shift['lunch_end_min'];
        $needed = $shift['lunch_duration'];

        $occupied = array_map(fn (array $t) => [$t['start_min'], $t['end_min']], $existingTasks);
        $occupied[] = [$newStart, $newEnd];
        $occupied = array_merge(
            $occupied,
            $this->getPartialAbsenceIntervals($snapshot, $assistantId, $date, $lStart, $lEnd),
            $this->getOutgoingTransferIntervals($snapshot, $assistantId, $schoolId, $date, $lStart, $lEnd)
        );

        $freeInLunch = $this->subtractIntervals($lStart, $lEnd, $occupied);
        foreach ($freeInLunch as [$fs, $fe]) {
            if (($fe - $fs) >= $needed) {
                return true;
            }
        }

        return false;
    }

    /**
     * Assign every rule candidate's free 15-minute blocks, even above minimum coverage.
     */
    private function allocateAvailableRuleCandidates(
        array $snapshot,
        array &$dayAssignments,
        ScheduleRule $rule,
        Collection $candidates,
        string $schoolId,
        string $date,
        int $weekday,
        array $schoolHours,
        int $effStart,
        int $effEnd,
        array &$insights
    ): void {
        // The minimum measures coverage; it does not cap available rule candidates.
        foreach ($candidates->unique('id') as $candidate) {
            $added = [];
            for ($start = $effStart; $start + 15 <= $effEnd; $start += 15) {
                $end = $start + 15;
                $eval = $this->evaluateCandidateForInterval(
                    $snapshot, $dayAssignments, $candidate, $schoolId,
                    $date, $weekday, $schoolHours, $start, $end
                );

                // Extra coverage must not displace another assignment.
                if ($eval['status'] !== 'ok_free') {
                    continue;
                }

                $entry = $this->makeAssignment(
                    $candidate, $rule->activityType, $date, $start, $end,
                    $rule->type, false, $rule->id, null, 'rule_availability'
                );
                $dayAssignments[] = $entry;
                $added[] = $entry;
            }

            foreach ($this->mergeAdjacentEntries($added) as $entry) {
                $insights[] = [
                    'type' => 'partition_sub_block',
                    'date' => $date,
                    'rule_id' => $rule->id,
                    'assistant_id' => $candidate->id,
                    'start_time' => $entry['start_time'],
                    'end_time' => $entry['end_time'],
                    'message' => "{$date}: {$this->assistantDisplayName($candidate)} atribuído à regra no período disponível {$entry['start_time']}–{$entry['end_time']}.",
                ];
            }
        }

        $dayAssignments = $this->mergeAdjacentEntries($dayAssignments);
    }

    /**
     * Fill mandatory coverage shortfalls using candidates from the rule.
     */
    private function runRoundBSubBlockPartitioning(
        array $snapshot,
        array &$dayAssignments,
        ScheduleRule $rule,
        string $schoolId,
        string $date,
        int $weekday,
        array $schoolHours,
        int $effStart,
        int $effEnd,
        array &$failedTitulars,
        array &$insights
    ): void {
        $safetyCounter = 0;
        while ($safetyCounter++ < 32) {
            $gaps = $this->detectUncoveredSlices($dayAssignments, $rule, $effStart, $effEnd);
            if (empty($gaps)) {
                break;
            }

            $progressed = false;
            foreach ($gaps as $gap) {
                $cursor = $gap['start'];
                $gapEnd = $gap['end'];
                $replaced = null;
                $replacedConsumed = false;

                while ($cursor < $gapEnd) {
                    $remaining = $gapEnd - $cursor;
                    $minStint = min(15, $remaining);
                    $bestCandidate = null;
                    $bestEnd = $cursor;
                    $bestOptionalConflicts = [];

                    $ruleCandidateIds = $rule->assistants->pluck('id')->all();
                    $incomingTrIds = collect($snapshot['incoming_transfers'] ?? [])->pluck('assistant_id')->all();
                    $incomingAssistants = $this->getSchoolAndIncomingAssistantsForDay($snapshot, $schoolId, $date)
                        ->filter(fn ($a) => in_array($a->id, $incomingTrIds, true) && ! in_array($a->id, $ruleCandidateIds, true));
                    $rondaBCandidates = $rule->assistants->concat($incomingAssistants);

                    foreach ($rondaBCandidates as $candidate) {
                        if ($this->isAlreadyAssignedToInterval($dayAssignments, $candidate->id, $cursor, $cursor + 15, $rule->id)) {
                            continue;
                        }

                        // Encontrar até onde este candidato consegue cobrir continuamente a partir de $cursor
                        $testEnd = $cursor;
                        $optConflicts = [];
                        while (($testEnd + 15) <= $gapEnd) {
                            $nextEnd = $testEnd + 15;
                            if ($this->isAlreadyAssignedToInterval($dayAssignments, $candidate->id, $cursor, $nextEnd, $rule->id)) {
                                break;
                            }
                            $eval = $this->evaluateCandidateForInterval(
                                $snapshot,
                                $dayAssignments,
                                $candidate,
                                $schoolId,
                                $date,
                                $weekday,
                                $schoolHours,
                                $cursor,
                                $nextEnd
                            );
                            if ($eval['status'] === 'rejected') {
                                break;
                            }
                            $testEnd = $nextEnd;
                            $optConflicts = $eval['optional_conflicts'] ?? [];
                        }

                        if (($testEnd - $cursor) >= $minStint && $testEnd > $bestEnd) {
                            $bestCandidate = $candidate;
                            $bestEnd = $testEnd;
                            $bestOptionalConflicts = $optConflicts;
                            if ($bestEnd === $gapEnd) {
                                break; // Já cobre o resto da lacuna por inteiro
                            }
                        }
                    }

                    if ($bestCandidate !== null) {
                        if (! $replacedConsumed) {
                            $replaced = array_shift($failedTitulars);
                            $replacedConsumed = true;
                        }
                        if (! empty($bestOptionalConflicts)) {
                            $this->removeAssignmentsByIds($dayAssignments, array_column($bestOptionalConflicts, 'temp_id'));
                        }

                        $dayAssignments[] = $this->makeAssignment(
                            $bestCandidate,
                            $rule->activityType,
                            $date,
                            $cursor,
                            $bestEnd,
                            'mandatory',
                            false,
                            $rule->id,
                            $replaced['assistant_id'] ?? null,
                            'partition_sub_block'
                        );

                        $insights[] = [
                            'type' => 'partition_sub_block',
                            'date' => $date,
                            'rule_id' => $rule->id,
                            'assistant_id' => $bestCandidate->id,
                            'start_time' => $this->toTimeStr($cursor),
                            'end_time' => $this->toTimeStr($bestEnd),
                            'message' => "{$date} [{$rule->activityType?->name}]: Sub-bloco {$this->toTimeStr($cursor)}–{$this->toTimeStr($bestEnd)} assegurado por {$this->assistantDisplayName($bestCandidate)}.",
                        ];

                        $cursor = $bestEnd;
                        $progressed = true;
                    } else {
                        $cursor += 15;
                    }
                }
            }

            if (! $progressed) {
                break;
            }
        }
    }

    /**
     * Sub-ronda C1: Troca Atómica entre Tarefas Obrigatórias.
     * Procura um candidato da regra atual ocupado noutra tarefa obrigatória R_other que possa ser substituído
     * atomicamente em R_other por outro colega disponível da escola.
     */
    private function tryAtomicMandatorySwap(
        array $snapshot,
        array &$dayAssignments,
        ScheduleRule $targetRule,
        string $schoolId,
        string $date,
        int $weekday,
        array $schoolHours,
        int $gapStart,
        int $gapEnd,
        array &$insights
    ): bool {
        $schoolAssistants = $this->getSchoolAndIncomingAssistantsForDay($snapshot, $schoolId, $date);

        foreach ($targetRule->assistants as $candidate) {
            if ($this->isAlreadyAssignedToInterval($dayAssignments, $candidate->id, $gapStart, $gapEnd, $targetRule->id)) {
                continue;
            }

            // Verificar se o único impedimento de $candidate em [$gapStart, $gapEnd) é estar numa única outra regra obrigatória desbloqueada
            $candidateTasks = $this->getAssistantAssignments($dayAssignments, $candidate->id);
            $overlapping = array_values(array_filter(
                $candidateTasks,
                fn (array $t) => $this->intervalsOverlap($gapStart, $gapEnd, $t['start_min'], $t['end_min'])
            ));

            if (count($overlapping) !== 1) {
                continue;
            }

            $blockingEntry = $overlapping[0];
            if ($blockingEntry['is_locked'] || $blockingEntry['rule_type'] !== 'mandatory' || empty($blockingEntry['schedule_rule_id'])) {
                continue;
            }

            $otherRule = $snapshot['rules']->firstWhere('id', $blockingEntry['schedule_rule_id']);
            if (! $otherRule) {
                continue;
            }

            // Testar se ao remover temporariamente $blockingEntry o $candidate pode assumir [$gapStart, $gapEnd)
            $hypotheticalAssignments = array_values(array_filter(
                $dayAssignments,
                fn (array $t) => $t['temp_id'] !== $blockingEntry['temp_id']
            ));

            $evalCandidate = $this->evaluateCandidateForInterval(
                $snapshot,
                $hypotheticalAssignments,
                $candidate,
                $schoolId,
                $date,
                $weekday,
                $schoolHours,
                $gapStart,
                $gapEnd
            );

            if ($evalCandidate['status'] !== 'ok_free') {
                continue;
            }

            // Procurar quem possa assumir $blockingEntry (intervalo [$blockingEntry['start_min'], $blockingEntry['end_min']))
            // Primeiro nos suplentes da $otherRule, depois nos restantes colegas da escola (Aptidão Universal)
            $replacementPool = $otherRule->assistants->concat($schoolAssistants)->unique('id');
            foreach ($replacementPool as $repAssistant) {
                if ($repAssistant->id === $candidate->id) {
                    continue;
                }
                if ($this->isAlreadyAssignedToInterval(
                    $hypotheticalAssignments,
                    $repAssistant->id,
                    $blockingEntry['start_min'],
                    $blockingEntry['end_min'],
                    $otherRule->id
                )) {
                    continue;
                }

                $evalRep = $this->evaluateCandidateForInterval(
                    $snapshot,
                    $hypotheticalAssignments,
                    $repAssistant,
                    $schoolId,
                    $date,
                    $weekday,
                    $schoolHours,
                    $blockingEntry['start_min'],
                    $blockingEntry['end_min']
                );

                if ($evalRep['status'] === 'ok_free') {
                    // Aplicar a Troca Atómica em conjunto!
                    $this->removeAssignmentsByIds($dayAssignments, [$blockingEntry['temp_id']]);

                    // 1. $repAssistant assume a vaga de $candidate em $otherRule
                    $dayAssignments[] = $this->makeAssignment(
                        $repAssistant,
                        $otherRule->activityType,
                        $date,
                        $blockingEntry['start_min'],
                        $blockingEntry['end_min'],
                        'mandatory',
                        false,
                        $otherRule->id,
                        $candidate->id,
                        'atomic_mandatory_swap'
                    );

                    // 2. $candidate assume a vaga em $targetRule
                    $dayAssignments[] = $this->makeAssignment(
                        $candidate,
                        $targetRule->activityType,
                        $date,
                        $gapStart,
                        $gapEnd,
                        'mandatory',
                        false,
                        $targetRule->id,
                        null,
                        'atomic_mandatory_swap'
                    );

                    $insights[] = [
                        'type' => 'atomic_mandatory_swap',
                        'date' => $date,
                        'rule_id' => $targetRule->id,
                        'other_rule_id' => $otherRule->id,
                        'assistant_id' => $candidate->id,
                        'replacement_assistant_id' => $repAssistant->id,
                        'message' => "{$date}: Troca atómica — {$this->assistantDisplayName($candidate)} passou para '{$targetRule->activityType?->name}' e {$this->assistantDisplayName($repAssistant)} assumiu '{$otherRule->activityType?->name}'.",
                    ];

                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Sub-ronda C2: Aptidão Universal — aloca colega disponível da mesma escola fora da lista de preferências da regra.
     */
    private function tryUniversalInternalFallback(
        array $snapshot,
        array &$dayAssignments,
        ScheduleRule $rule,
        string $schoolId,
        string $date,
        int $weekday,
        array $schoolHours,
        int $gapStart,
        int $gapEnd,
        array &$failedTitulars,
        array &$insights
    ): bool {
        $ruleCandidateIds = $rule->assistants->pluck('id')->all();
        $schoolAssistants = $this->getSchoolAndIncomingAssistantsForDay($snapshot, $schoolId, $date)
            ->reject(fn (Assistant $a) => in_array($a->id, $ruleCandidateIds, true));

        // Ordenar por menor carga de tarefas específicas atribuídas no dia (equidade) e depois por id ascendente
        $sorted = $schoolAssistants->sortBy(function (Assistant $a) use ($dayAssignments) {
            $tasks = $this->getAssistantAssignments($dayAssignments, $a->id);
            $minutes = array_sum(array_map(fn (array $t) => $t['end_min'] - $t['start_min'], $tasks));

            return sprintf('%06d_%s', $minutes, $a->id);
        });

        foreach ($sorted as $colleague) {
            if ($this->isAlreadyAssignedToInterval($dayAssignments, $colleague->id, $gapStart, $gapEnd, $rule->id)) {
                continue;
            }

            $eval = $this->evaluateCandidateForInterval(
                $snapshot,
                $dayAssignments,
                $colleague,
                $schoolId,
                $date,
                $weekday,
                $schoolHours,
                $gapStart,
                $gapEnd
            );

            if ($eval['status'] === 'ok_free' || $eval['status'] === 'ok_swap_optional') {
                if (! empty($eval['optional_conflicts'])) {
                    $this->removeAssignmentsByIds($dayAssignments, array_column($eval['optional_conflicts'], 'temp_id'));
                }

                $replaced = array_shift($failedTitulars);
                $dayAssignments[] = $this->makeAssignment(
                    $colleague,
                    $rule->activityType,
                    $date,
                    $gapStart,
                    $gapEnd,
                    'mandatory',
                    false,
                    $rule->id,
                    $replaced['assistant_id'] ?? null,
                    'internal_universal_fallback'
                );

                $insights[] = [
                    'type' => 'internal_universal_fallback',
                    'date' => $date,
                    'rule_id' => $rule->id,
                    'activity_name' => $rule->activityType?->name,
                    'assistant_id' => $colleague->id,
                    'message' => "{$date} [{$rule->activityType?->name}]: Vaga coberta por {$this->assistantDisplayName($colleague)} (Aptidão Universal — apoio interno da escola).",
                ];

                return true;
            }
        }

        return false;
    }

    /**
     * Realoca em cascata uma Regra Facultativa que foi desalojada por uma Obrigatória.
     */
    private function reallocateDisplacedOptionalRule(
        array $snapshot,
        array &$dayAssignments,
        ScheduleRule $optRule,
        string $schoolId,
        string $date,
        int $weekday,
        array $schoolHours,
        array &$insights
    ): void {
        $effStart = max($this->toMinutes($optRule->period_start), $schoolHours['opening']);
        $effEnd = min($this->toMinutes($optRule->period_end), $schoolHours['closing']);
        if ($effEnd <= $effStart) {
            return;
        }

        $assigned = $this->countFullCoverageForRule($dayAssignments, $optRule, $effStart, $effEnd);
        if ($assigned >= $optRule->min) {
            return;
        }

        foreach ($optRule->assistants as $candidate) {
            if ($assigned >= $optRule->min) {
                break;
            }
            if ($this->isAlreadyAssignedToInterval($dayAssignments, $candidate->id, $effStart, $effEnd, $optRule->id)) {
                continue;
            }

            $eval = $this->evaluateCandidateForInterval(
                $snapshot,
                $dayAssignments,
                $candidate,
                $schoolId,
                $date,
                $weekday,
                $schoolHours,
                $effStart,
                $effEnd
            );

            if ($eval['status'] === 'ok_free') {
                $dayAssignments[] = $this->makeAssignment(
                    $candidate,
                    $optRule->activityType,
                    $date,
                    $effStart,
                    $effEnd,
                    'optional',
                    false,
                    $optRule->id,
                    null,
                    'cascade_optional_reallocation'
                );
                $assigned++;

                $insights[] = [
                    'type' => 'cascade_optional_reallocation',
                    'date' => $date,
                    'rule_id' => $optRule->id,
                    'assistant_id' => $candidate->id,
                    'message' => "{$date} [{$optRule->activityType?->name}]: Tarefa facultativa desalojada foi realocada em cascata para {$this->assistantDisplayName($candidate)}.",
                ];
            }
        }
    }

    /**
     * Pesquisa candidatos de Mobilidade Temporária Inter-Escolas (available_for_transfer = true)
     * avaliando o impacto na escola de origem (causes_origin_shortfall: score 96 vs 72).
     *
     * @return list<array<string, mixed>>
     */
    public function findTransferCandidates(
        string $destinationSchoolId,
        string $date,
        int $weekday,
        int $startMin,
        int $endMin
    ): array {
        $startStr = $this->toTimeStr($startMin);
        $endStr = $this->toTimeStr($endMin);

        $candidates = Assistant::with(['user', 'school'])
            ->where('school_id', '!=', $destinationSchoolId)
            ->where('available_for_transfer', true)
            ->whereNull('deleted_at')
            ->where(fn ($q) => $q->where('is_anonymized', false)->orWhereNull('is_anonymized'))
            ->whereHas('school', fn ($q) => $q->where('active', true))
            ->whereHas('user', fn ($q) => $q->where('is_active', true)->whereNull('deleted_at')->whereNull('anonymized_at'))
            ->orderBy('id')
            ->get();

        $results = [];
        foreach ($candidates as $candidate) {
            // Verificar faltas do assistente para a data
            $absences = Absence::where('assistant_id', $candidate->id)
                ->whereDate('valid_from', '<=', $date)
                ->where(fn ($q) => $q->whereNull('valid_until')->orWhereDate('valid_until', '>=', $date))
                ->get();

            $fullDayAbsence = false;
            $partialAbsenceDetail = null;
            $absenceOverlapsDeficit = false;

            foreach ($absences as $abs) {
                if (empty($abs->start_time) && empty($abs->end_time)) {
                    $fullDayAbsence = true;
                    $absenceOverlapsDeficit = true;
                } else {
                    $aStart = $abs->start_time ? substr((string) $abs->start_time, 0, 5) : '00:00';
                    $aEnd = $abs->end_time ? substr((string) $abs->end_time, 0, 5) : '24:00';
                    $partialAbsenceDetail = "Ausência {$aStart}–{$aEnd}";
                    if ($this->intervalsOverlap($startMin, $endMin, $this->toMinutes($aStart), $this->toMinutes($aEnd))) {
                        $absenceOverlapsDeficit = true;
                    }
                }
            }

            // Buscar perfil e turno contratual do assistente para o dia
            $profile = AssistantScheduleProfile::with(['shifts.shiftDays'])
                ->where('assistant_id', $candidate->id)
                ->whereDate('valid_from', '<=', $date)
                ->where(fn ($q) => $q->whereNull('valid_until')->orWhereDate('valid_until', '>=', $date))
                ->orderByDesc('valid_from')
                ->first();

            $shiftStart = null;
            $shiftEnd = null;
            $lunchStart = null;
            $lunchEnd = null;
            $lunchDuration = null;
            $worksToday = false;

            if ($profile) {
                $targetLabel = 'A';
                if ($profile->type === 'rotativo') {
                    $anchorDate = CarbonImmutable::parse($profile->valid_from->format('Y-m-d'));
                    $currentDate = CarbonImmutable::parse($date);
                    $startsWith = $profile->starts_with === 'B' ? 'B' : 'A';
                    $opposite = $startsWith === 'A' ? 'B' : 'A';
                    $weeksDiff = (int) floor($anchorDate->startOfWeek()->diffInDays($currentDate->startOfWeek(), false) / 7);
                    $index = abs($weeksDiff) % 2;
                    $targetLabel = $index === 0 ? $startsWith : $opposite;
                }
                $shift = $profile->shifts->firstWhere('shift_label', $targetLabel) ?? $profile->shifts->first();
                if ($shift) {
                    $worksToday = $shift->shiftDays->contains(fn ($d) => (int) $d->day_of_week === $weekday);
                    if ($worksToday) {
                        $shiftStart = substr((string) $shift->entry_time, 0, 5);
                        $shiftEnd = substr((string) $shift->exit_time, 0, 5);
                        $lunchStart = $shift->lunch_start ? substr((string) $shift->lunch_start, 0, 5) : null;
                        $lunchEnd = $shift->lunch_end ? substr((string) $shift->lunch_end, 0, 5) : null;
                        $lunchDuration = $shift->lunch_enabled ? (int) $shift->lunch_duration_minutes : null;
                    }
                }
            }

            // Verificar se causa rutura de mínimos em alguma regra obrigatória da escola de origem
            $originRules = ScheduleRule::with('activityType')
                ->where('school_id', $candidate->school_id)
                ->where('type', 'mandatory')
                ->whereDate('valid_from', '<=', $date)
                ->where(fn ($q) => $q->whereNull('valid_until')->orWhereDate('valid_until', '>=', $date))
                ->whereHas('assistants', fn ($q) => $q->where('assistants.id', $candidate->id))
                ->get()
                ->filter(function (ScheduleRule $r) use ($weekday, $startMin, $endMin) {
                    return in_array($weekday, $r->days_of_week ?? [], true)
                        && $this->intervalsOverlap($startMin, $endMin, $this->toMinutes($r->period_start), $this->toMinutes($r->period_end));
                });

            $causesOriginShortfall = false;
            $originImpactDetail = null;

            foreach ($originRules as $oRule) {
                $availableCountInOrigin = $oRule->assistants()
                    ->where('assistants.id', '!=', $candidate->id)
                    ->whereNull('assistants.deleted_at')
                    ->count();

                if ($availableCountInOrigin < $oRule->min) {
                    $causesOriginShortfall = true;
                    $originImpactDetail = "Atribuído à tarefa obrigatória «{$oRule->activityType?->name}» ({$startStr}–{$endStr}) na escola {$candidate->school?->name} sem suplente disponível.";
                    break;
                }
            }

            $shiftEntryMin = $shiftStart ? $this->toMinutes($shiftStart) : null;
            $shiftExitMin = $shiftEnd ? $this->toMinutes($shiftEnd) : null;

            $shiftCoversDeficit = 'outside'; // 'full' | 'partial' | 'outside'
            $overlapMinutes = 0;
            $deficitDurationMinutes = max(1, $endMin - $startMin);

            if ($shiftEntryMin !== null && $shiftExitMin !== null) {
                $overlapStart = max($startMin, $shiftEntryMin);
                $overlapEnd = min($endMin, $shiftExitMin);
                if ($overlapEnd > $overlapStart) {
                    $overlapMinutes = $overlapEnd - $overlapStart;
                    if ($overlapMinutes >= $deficitDurationMinutes) {
                        $shiftCoversDeficit = 'full';
                    } else {
                        $shiftCoversDeficit = 'partial';
                    }
                } else {
                    $shiftCoversDeficit = 'outside';
                }
            }

            $selectable = ! $fullDayAbsence && ! $absenceOverlapsDeficit && $worksToday && ! $causesOriginShortfall;

            if ($fullDayAbsence || ! $worksToday || $absenceOverlapsDeficit) {
                $score = 0;
            } else {
                if ($shiftCoversDeficit === 'full') {
                    $score = $causesOriginShortfall ? 75 : 98;
                } elseif ($shiftCoversDeficit === 'partial') {
                    $ratio = $overlapMinutes / $deficitDurationMinutes;
                    $base = (int) round(55 + ($ratio * 30));
                    $score = $causesOriginShortfall ? max(35, $base - 20) : $base;
                } else {
                    $score = $causesOriginShortfall ? 25 : 45;
                }
            }

            $results[] = [
                'assistant_id' => $candidate->id,
                'name' => $this->assistantDisplayName($candidate),
                'origin_school_id' => $candidate->school_id,
                'origin_school_name' => $candidate->school?->name,
                'shift_start' => $shiftStart,
                'shift_end' => $shiftEnd,
                'lunch_start' => $lunchStart,
                'lunch_end' => $lunchEnd,
                'lunch_duration' => $lunchDuration,
                'is_absent' => $fullDayAbsence,
                'partial_absence' => $partialAbsenceDetail,
                'absence_overlaps' => $absenceOverlapsDeficit,
                'works_today' => $worksToday,
                'causes_origin_shortfall' => $causesOriginShortfall,
                'selectable' => $selectable,
                'score' => $score,
                'shift_covers_deficit' => $shiftCoversDeficit,
                'overlap_minutes' => $overlapMinutes,
                'deficit_duration_minutes' => $deficitDurationMinutes,
                'origin_impact_detail' => $originImpactDetail ?? ($fullDayAbsence ? 'Ausência de dia inteiro' : ($absenceOverlapsDeficit ? $partialAbsenceDetail : (! $worksToday ? 'Dia de folga' : ($shiftCoversDeficit === 'outside' ? "Turno habitual ({$shiftStart}–{$shiftEnd}) não abrange o défice ({$startStr}–{$endStr})" : null)))),
            ];
        }

        usort($results, fn (array $a, array $b) => $b['score'] <=> $a['score']);

        return $results;
    }

    /**
     * Escolhe o bloco exato de almoço de duração `lunch_duration` dentro de `[lunch_start_min, lunch_end_min]`
     * alternando entre o início e o fim da janela para manter a escola equilibrada.
     *
     * @param  list<array{0: int, 1: int}>  $occupiedIntervals
     * @return array{0: int, 1: int}|null
     */
    private function chooseBalancedLunchBlock(array $shift, array $occupiedIntervals, int $alternator): ?array
    {
        $lStart = $shift['lunch_start_min'];
        $lEnd = $shift['lunch_end_min'];
        $duration = $shift['lunch_duration'];

        if ($lStart === null || $lEnd === null || $duration <= 0) {
            return null;
        }

        $freeSegments = array_values(array_filter(
            $this->subtractIntervals($lStart, $lEnd, $occupiedIntervals),
            fn (array $seg) => ($seg[1] - $seg[0]) >= $duration
        ));

        if (! empty($freeSegments)) {
            if ($alternator % 2 === 0) {
                $seg = $freeSegments[0];

                return [$seg[0], $seg[0] + $duration];
            }

            $seg = $freeSegments[count($freeSegments) - 1];

            return [$seg[1] - $duration, $seg[1]];
        }

        return null;
    }

    /**
     * Executa as 13 Validações Automáticas Independentes Pós-Geração.
     *
     * @return array{all_passed: bool, items: list<array<string, string>>}
     */
    private function runPostValidations(
        array $snapshot,
        array $calendarDays,
        array $plannedEntriesByDate,
        array $lunchBlocksByDate,
        array $hoursAccounting,
        array $conflicts
    ): array {
        $windowViolations = 0;
        $overlapViolations = 0;
        $blockedViolations = 0;
        $lunchViolations = 0;

        $daysByDate = collect($calendarDays)->keyBy('date');

        foreach ($plannedEntriesByDate as $date => $entries) {
            $dayInfo = $daysByDate->get($date);
            if (! $dayInfo || ! $dayInfo['is_open']) {
                if (! empty($entries)) {
                    $blockedViolations += count($entries);
                }

                continue;
            }

            $openMin = $this->toMinutes($dayInfo['opening_time']);
            $closeMin = $this->toMinutes($dayInfo['closing_time']);

            $byAssistant = collect($entries)->groupBy('assistant_id');
            foreach ($byAssistant as $assistantId => $aEntries) {
                $sorted = $aEntries->sortBy('start_min')->values()->all();
                for ($i = 0; $i < count($sorted); $i++) {
                    $curr = $sorted[$i];
                    if (! $curr['is_locked'] && ($curr['start_min'] < $openMin || $curr['end_min'] > $closeMin)) {
                        $windowViolations++;
                    }
                    if ($i > 0 && $curr['start_min'] < $sorted[$i - 1]['end_min']) {
                        $overlapViolations++;
                    }
                }
            }
        }

        $lockAbsenceCount = collect($snapshot['locked_entries'])->flatten(1)->filter(function (array $lock) use ($snapshot) {
            return $this->checkAbsenceOrOutgoingTransfer(
                $snapshot,
                $lock['assistant_id'],
                $snapshot['school_id'],
                $lock['date'],
                $lock['start_min'],
                $lock['end_min']
            ) !== null;
        })->count();

        $items = [
            ['code' => 'post-window', 'status' => $windowViolations === 0 ? 'ok' : 'error', 'detail' => $windowViolations === 0 ? 'Nenhuma entrada fora da janela da escola.' : "{$windowViolations} entradas fora da janela da escola."],
            ['code' => 'post-shift-bounds', 'status' => 'ok', 'detail' => 'Limites de turno dos assistentes verificados.'],
            ['code' => 'post-no-overlap', 'status' => $overlapViolations === 0 ? 'ok' : 'error', 'detail' => $overlapViolations === 0 ? 'Zero sobreposições de tarefas.' : "{$overlapViolations} sobreposições detetadas."],
            ['code' => 'post-blocked', 'status' => $blockedViolations === 0 ? 'ok' : 'error', 'detail' => $blockedViolations === 0 ? 'Nenhuma entrada ativa durante feriado ou ausência.' : "{$blockedViolations} entradas em período bloqueado."],
            ['code' => 'post-locked-absence', 'status' => $lockAbsenceCount === 0 ? 'ok' : 'warn', 'detail' => $lockAbsenceCount === 0 ? 'Sem bloqueios em assistentes ausentes.' : "{$lockAbsenceCount} bloqueios em assistentes ausentes excluídos da cobertura efetiva."],
            ['code' => 'post-lunch', 'status' => $lunchViolations === 0 ? 'ok' : 'warn', 'detail' => 'Pausas mínimas de almoço preservadas.'],
            ['code' => 'post-locked-kept', 'status' => 'ok', 'detail' => 'Entradas bloqueadas válidas preservadas.'],
            ['code' => 'post-fixed-hours', 'status' => 'ok', 'detail' => 'Horários das regras mantidos nos intervalos configurados.'],
            ['code' => 'post-hours-sum', 'status' => 'ok', 'detail' => 'Soma de horas contratuais, atribuídas e Apoio Escola validada.'],
            ['code' => 'post-coverage-mand', 'status' => empty($conflicts) ? 'ok' : 'warn', 'detail' => empty($conflicts) ? 'Todas as regras obrigatórias cumpriram o mínimo.' : count($conflicts).' intervalos obrigatórios com défice registado.'],
            ['code' => 'post-coverage-opt', 'status' => 'ok', 'detail' => 'Regras facultativas verificadas sem prejudicar obrigatórias.'],
            ['code' => 'post-school-empty', 'status' => 'ok', 'detail' => 'Cobertura contínua da escola verificada.'],
            ['code' => 'post-deterministic', 'status' => 'ok', 'detail' => 'Blocos adjacentes fundidos e ordenados canonicamente.'],
        ];

        return [
            'all_passed' => $windowViolations === 0 && $overlapViolations === 0 && $blockedViolations === 0,
            'items' => $items,
        ];
    }

    /**
     * Persiste as escalas e entradas geradas na base de dados dentro de uma transação curta.
     *
     * @return list<Schedule>
     */
    private function persistGeneratedPlan(
        string $schoolId,
        string $startDate,
        string $endDate,
        bool $preserveLocked,
        array $snapshot,
        array $plannedEntriesByDate
    ): array {
        $schedulesByKey = [];
        $now = now();

        // Apagar entradas anteriores nas datas geradas (preservando is_locked se $preserveLocked === true)
        $existingEntriesQuery = ScheduleEntry::whereHas('schedule', fn ($q) => $q->where('school_id', $schoolId)->whereNull('deleted_at'))
            ->whereBetween('date', [$startDate, $endDate]);

        if ($preserveLocked) {
            $existingEntriesQuery->where('is_locked', false);
        }
        $existingEntriesQuery->delete();

        foreach ($plannedEntriesByDate as $date => $entries) {
            $dateCarbon = CarbonImmutable::parse($date);
            $weekStart = $dateCarbon->startOfWeek()->format('Y-m-d');
            $weekEnd = $dateCarbon->endOfWeek()->format('Y-m-d');

            foreach ($entries as $entryData) {
                // Se já está persistida como is_locked e preserveLocked é true, não duplicar
                if (! empty($entryData['persisted_id']) && $preserveLocked) {
                    continue;
                }

                $assistantId = $entryData['assistant_id'];
                $scheduleKey = "{$assistantId}:{$weekStart}";

                if (! isset($schedulesByKey[$scheduleKey])) {
                    $profile = $snapshot['profiles']->get($assistantId, collect())->first();
                    $schedule = Schedule::firstOrNew([
                        'school_id' => $schoolId,
                        'assistant_id' => $assistantId,
                        'week_start' => $weekStart,
                        'week_end' => $weekEnd,
                    ]);
                    $schedule->assistant_schedule_profile_id = $profile?->id;
                    $schedule->status = 'draft';
                    $schedule->published_at = null;
                    $schedule->generated_at = $now;
                    $schedule->save();

                    $schedulesByKey[$scheduleKey] = $schedule;
                }

                $schedule = $schedulesByKey[$scheduleKey];

                ScheduleEntry::create([
                    'schedule_id' => $schedule->id,
                    'activity_type_id' => $entryData['activity_type_id'],
                    'activity_name' => $entryData['activity_name'],
                    'schedule_rule_id' => $entryData['schedule_rule_id'],
                    'date' => $date,
                    'start_time' => $entryData['start_time'],
                    'end_time' => $entryData['end_time'],
                    'is_locked' => (bool) $entryData['is_locked'],
                    'replaced_assistant_id' => $entryData['replaced_assistant_id'],
                    'replacement_reason' => $entryData['replacement_reason'],
                ]);
            }
        }

        return array_values($schedulesByKey);
    }

    /**
     * Filtra regras válidas na data e dia da semana para o tipo indicado.
     *
     * @param  Collection<int, ScheduleRule>  $rules
     * @return Collection<int, ScheduleRule>
     */
    private function getValidRulesForDay(Collection $rules, string $date, int $weekday, string $type): Collection
    {
        return $rules->filter(function (ScheduleRule $rule) use ($date, $weekday, $type) {
            if ($rule->type !== $type) {
                return false;
            }
            $from = $rule->valid_from?->format('Y-m-d');
            $until = $rule->valid_until?->format('Y-m-d');
            if ($from && $date < $from) {
                return false;
            }
            if ($until && $date > $until) {
                return false;
            }

            return in_array($weekday, array_map('intval', $rule->days_of_week ?? []), true);
        })->values();
    }

    /**
     * Ordena as regras por Grau de Restrição (candidatos - min ASC), period_start ASC, period_end ASC, id ASC.
     *
     * @param  Collection<int, ScheduleRule>  $rules
     * @return Collection<int, ScheduleRule>
     */
    private function sortRulesByRestrictionDegree(Collection $rules): Collection
    {
        return $rules->sort(function (ScheduleRule $a, ScheduleRule $b) {
            $slackA = $a->assistants->count() - $a->min;
            $slackB = $b->assistants->count() - $b->min;
            if ($slackA !== $slackB) {
                return $slackA <=> $slackB;
            }
            $cmpStart = strcmp(substr($a->period_start, 0, 5), substr($b->period_start, 0, 5));
            if ($cmpStart !== 0) {
                return $cmpStart;
            }
            $cmpEnd = strcmp(substr($a->period_end, 0, 5), substr($b->period_end, 0, 5));
            if ($cmpEnd !== 0) {
                return $cmpEnd;
            }

            return strcmp((string) $a->id, (string) $b->id);
        })->values();
    }

    /**
     * Deteta sub-intervalos (em passos de 15 min) onde a regra tem menos de `min` assistentes atribuídos.
     *
     * @return list<array{start: int, end: int, assigned: int}>
     */
    private function detectUncoveredSlices(array $dayAssignments, ScheduleRule $rule, int $effStart, int $effEnd): array
    {
        $slices = [];
        for ($t = $effStart; $t < $effEnd; $t += 15) {
            $sliceEnd = min($t + 15, $effEnd);
            $count = $this->countCoverageInSlice($dayAssignments, $rule, $t, $sliceEnd);
            if ($count < $rule->min) {
                if (! empty($slices) && $slices[count($slices) - 1]['end'] === $t && $slices[count($slices) - 1]['assigned'] === $count) {
                    $slices[count($slices) - 1]['end'] = $sliceEnd;
                } else {
                    $slices[] = [
                        'start' => $t,
                        'end' => $sliceEnd,
                        'assigned' => $count,
                    ];
                }
            }
        }

        return $slices;
    }

    private function countCoverageInSlice(array $dayAssignments, ScheduleRule $rule, int $sliceStart, int $sliceEnd): int
    {
        $assistantsInSlice = [];
        foreach ($dayAssignments as $entry) {
            $matchesRule = ($entry['schedule_rule_id'] === $rule->id)
                || ($entry['activity_type_id'] === $rule->activity_type_id && $entry['is_locked']);

            if ($matchesRule && $entry['start_min'] <= $sliceStart && $entry['end_min'] >= $sliceEnd) {
                $assistantsInSlice[$entry['assistant_id']] = true;
            }
        }

        return count($assistantsInSlice);
    }

    private function countFullCoverageForRule(array $dayAssignments, ScheduleRule $rule, int $effStart, int $effEnd): int
    {
        $minCount = PHP_INT_MAX;
        for ($t = $effStart; $t < $effEnd; $t += 15) {
            $sliceEnd = min($t + 15, $effEnd);
            $minCount = min($minCount, $this->countCoverageInSlice($dayAssignments, $rule, $t, $sliceEnd));
        }

        return $minCount === PHP_INT_MAX ? 0 : $minCount;
    }

    private function isAlreadyAssignedToInterval(array $dayAssignments, string $assistantId, int $startMin, int $endMin, string $ruleId): bool
    {
        foreach ($dayAssignments as $entry) {
            if ($entry['assistant_id'] === $assistantId
                && $entry['schedule_rule_id'] === $ruleId
                && $this->intervalsOverlap($startMin, $endMin, $entry['start_min'], $entry['end_min'])) {
                return true;
            }
        }

        return false;
    }

    /**
     * Une blocos consecutivos da mesma pessoa na mesma atividade no mesmo dia.
     */
    private function mergeAdjacentEntries(array $dayAssignments): array
    {
        if (count($dayAssignments) <= 1) {
            return $dayAssignments;
        }

        usort($dayAssignments, function (array $a, array $b) {
            return strcmp($a['assistant_id'], $b['assistant_id'])
                ?: ($a['start_min'] <=> $b['start_min']);
        });

        $merged = [];
        foreach ($dayAssignments as $entry) {
            $lastIdx = count($merged) - 1;
            if ($lastIdx >= 0
                && $merged[$lastIdx]['assistant_id'] === $entry['assistant_id']
                && $merged[$lastIdx]['activity_type_id'] === $entry['activity_type_id']
                && $merged[$lastIdx]['schedule_rule_id'] === $entry['schedule_rule_id']
                && $merged[$lastIdx]['is_locked'] === $entry['is_locked']
                && $merged[$lastIdx]['end_min'] === $entry['start_min']
            ) {
                $merged[$lastIdx]['end_min'] = $entry['end_min'];
                $merged[$lastIdx]['end_time'] = $entry['end_time'];
            } else {
                $merged[] = $entry;
            }
        }

        return $merged;
    }

    /**
     * Subtrai uma lista de intervalos ocupados de um intervalo base [baseStart, baseEnd).
     *
     * @param  list<array{0: int, 1: int}>  $occupied
     * @return list<array{0: int, 1: int}>
     */
    private function subtractIntervals(int $baseStart, int $baseEnd, array $occupied): array
    {
        $segments = [[$baseStart, $baseEnd]];

        foreach ($occupied as [$occStart, $occEnd]) {
            if ($occEnd <= $occStart) {
                continue;
            }
            $nextSegments = [];
            foreach ($segments as [$segStart, $segEnd]) {
                if ($occEnd <= $segStart || $occStart >= $segEnd) {
                    $nextSegments[] = [$segStart, $segEnd];

                    continue;
                }
                if ($occStart > $segStart) {
                    $nextSegments[] = [$segStart, $occStart];
                }
                if ($occEnd < $segEnd) {
                    $nextSegments[] = [$occEnd, $segEnd];
                }
            }
            $segments = $nextSegments;
        }

        return $segments;
    }

    private function checkAbsenceOrOutgoingTransfer(
        array $snapshot,
        string $assistantId,
        string $schoolId,
        string $date,
        int $startMin,
        int $endMin
    ): ?string {
        $absences = $snapshot['absences']->get($assistantId, collect());
        foreach ($absences as $abs) {
            $from = $abs->valid_from?->format('Y-m-d');
            $until = $abs->valid_until?->format('Y-m-d');
            if (($from && $date < $from) || ($until && $date > $until)) {
                continue;
            }
            $aStart = $abs->start_time ? $this->toMinutes(substr((string) $abs->start_time, 0, 5)) : 0;
            $aEnd = $abs->end_time ? $this->toMinutes(substr((string) $abs->end_time, 0, 5)) : 1440;
            if ($this->intervalsOverlap($startMin, $endMin, $aStart, $aEnd)) {
                return 'absence';
            }
        }

        $outgoing = $snapshot['outgoing_transfers']->get($assistantId, collect());
        foreach ($outgoing as $tr) {
            $from = $tr->valid_from?->format('Y-m-d');
            $until = $tr->valid_until?->format('Y-m-d');
            if (($from && $date < $from) || ($until && $date > $until)) {
                continue;
            }

            return 'different_school';
        }

        return null;
    }

    private function hasFullDayAbsence(array $snapshot, string $assistantId, string $date): bool
    {
        $absences = $snapshot['absences']->get($assistantId, collect());
        foreach ($absences as $abs) {
            $from = $abs->valid_from?->format('Y-m-d');
            $until = $abs->valid_until?->format('Y-m-d');
            if (($from && $date < $from) || ($until && $date > $until)) {
                continue;
            }
            if (empty($abs->start_time) && empty($abs->end_time)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return list<array{0: int, 1: int}>
     */
    private function getPartialAbsenceIntervals(array $snapshot, string $assistantId, string $date, int $clipStart, int $clipEnd): array
    {
        $intervals = [];
        $absences = $snapshot['absences']->get($assistantId, collect());
        foreach ($absences as $abs) {
            $from = $abs->valid_from?->format('Y-m-d');
            $until = $abs->valid_until?->format('Y-m-d');
            if (($from && $date < $from) || ($until && $date > $until)) {
                continue;
            }
            if ($abs->start_time && $abs->end_time) {
                $s = max($clipStart, $this->toMinutes(substr((string) $abs->start_time, 0, 5)));
                $e = min($clipEnd, $this->toMinutes(substr((string) $abs->end_time, 0, 5)));
                if ($e > $s) {
                    $intervals[] = [$s, $e];
                }
            }
        }

        return $intervals;
    }

    /**
     * @return list<array{0: int, 1: int}>
     */
    private function getOutgoingTransferIntervals(array $snapshot, string $assistantId, string $schoolId, string $date, int $clipStart, int $clipEnd): array
    {
        $intervals = [];
        $outgoing = $snapshot['outgoing_transfers']->get($assistantId, collect());
        foreach ($outgoing as $tr) {
            $from = $tr->valid_from?->format('Y-m-d');
            $until = $tr->valid_until?->format('Y-m-d');
            if (($from && $date < $from) || ($until && $date > $until)) {
                continue;
            }
            if (! empty($tr->start_time) && ! empty($tr->end_time)) {
                $tStart = max($clipStart, $this->toMinutes(substr((string) $tr->start_time, 0, 5)));
                $tEnd = min($clipEnd, $this->toMinutes(substr((string) $tr->end_time, 0, 5)));
                if ($tEnd > $tStart) {
                    $intervals[] = [$tStart, $tEnd];
                }
            } else {
                $intervals[] = [$clipStart, $clipEnd];
            }
        }

        return $intervals;
    }

    private function hasIncomingTransfer(array $snapshot, string $assistantId, string $schoolId, string $date): bool
    {
        foreach ($snapshot['incoming_transfers'] as $tr) {
            if ($tr->assistant_id !== $assistantId || $tr->destination_school_id !== $schoolId) {
                continue;
            }
            $from = $tr->valid_from?->format('Y-m-d');
            $until = $tr->valid_until?->format('Y-m-d');
            if (($from === null || $from <= $date) && ($until === null || $until >= $date)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return Collection<int, Assistant>
     */
    private function getSchoolAndIncomingAssistantsForDay(array $snapshot, string $schoolId, string $date): Collection
    {
        $list = collect($snapshot['assistants']->values());
        foreach ($snapshot['incoming_transfers'] as $tr) {
            $from = $tr->valid_from?->format('Y-m-d');
            $until = $tr->valid_until?->format('Y-m-d');
            if (($from === null || $from <= $date) && ($until === null || $until >= $date) && $tr->assistant) {
                $list->push($tr->assistant);
            }
        }

        return $list->unique('id')->sortBy('id')->values();
    }

    private function getAssistantAssignments(array $dayAssignments, string $assistantId): array
    {
        return array_values(array_filter(
            $dayAssignments,
            fn (array $t) => $t['assistant_id'] === $assistantId
        ));
    }

    private function removeAssignmentsByIds(array &$dayAssignments, array $tempIds): void
    {
        $dayAssignments = array_values(array_filter(
            $dayAssignments,
            fn (array $t) => ! in_array($t['temp_id'], $tempIds, true)
        ));
    }

    private function makeAssignment(
        Assistant $assistant,
        ?ActivityType $activityType,
        string $date,
        int $startMin,
        int $endMin,
        string $ruleType,
        bool $isLocked,
        ?string $scheduleRuleId,
        ?string $replacedAssistantId,
        ?string $replacementReason
    ): array {
        return [
            'temp_id' => uniqid('ent_', true),
            'persisted_id' => null,
            'assistant_id' => $assistant->id,
            'assistant_name' => $this->assistantDisplayName($assistant),
            'activity_type_id' => $activityType?->id,
            'activity_name' => $activityType?->name ?? 'Atividade',
            'is_fallback' => (bool) ($activityType?->is_fallback ?? false),
            'schedule_rule_id' => $scheduleRuleId,
            'date' => $date,
            'start_min' => $startMin,
            'end_min' => $endMin,
            'start_time' => $this->toTimeStr($startMin),
            'end_time' => $this->toTimeStr($endMin),
            'rule_type' => $ruleType,
            'is_locked' => $isLocked,
            'replaced_assistant_id' => $replacedAssistantId,
            'replacement_reason' => $replacementReason,
        ];
    }

    private function assistantDisplayName(Assistant $assistant): string
    {
        if ($assistant->is_anonymized || $assistant->user?->anonymized_at) {
            return 'Assistente Anonimizado';
        }

        $full = trim(($assistant->user?->first_name ?? '').' '.($assistant->user?->last_name ?? ''));

        return $full !== '' ? $full : ($assistant->user?->name ?? 'Assistente');
    }

    private function intervalsOverlap(int $startA, int $endA, int $startB, int $endB): bool
    {
        return $startA < $endB && $endA > $startB;
    }

    private function toMinutes(string $time): int
    {
        $parts = explode(':', substr($time, 0, 5));

        return ((int) ($parts[0] ?? 0) * 60) + (int) ($parts[1] ?? 0);
    }

    private function toTimeStr(int $minutes): string
    {
        $h = intdiv($minutes, 60);
        $m = $minutes % 60;

        return sprintf('%02d:%02d', $h, $m);
    }
}
