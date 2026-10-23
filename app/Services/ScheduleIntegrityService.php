<?php

namespace App\Services;

use App\Models\Absences\Absence;
use App\Models\Assistants\Assistant;
use App\Models\Assistants\AssistantException;
use App\Models\Schedules\Schedule;
use App\Models\Schedules\ScheduleEntry;
use App\Models\Schedules\ScheduleRule;
use App\Models\Schools\School;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

class ScheduleIntegrityService
{
    public function __construct(
        private readonly OperatingHoursResolver $operatingHoursResolver
    ) {}

    /**
     * Devolve a lista de dias futuros (>= TODAY) com escalas geradas que contêm conflitos
     * ou regras em quebra devido a faltas supervenientes, alterações de regras ou encerramentos.
     *
     * @return array<string, mixed>
     */
    public function getPendingRecalculations(string $schoolId): array
    {
        $today = CarbonImmutable::today()->toDateString();

        // 1. Obter todas as datas >= hoje que têm entradas de escala geradas para esta escola
        $datesWithSchedules = ScheduleEntry::query()
            ->whereHas('schedule', fn ($q) => $q->where('school_id', $schoolId))
            ->where('date', '>=', $today)
            ->distinct()
            ->orderBy('date')
            ->pluck('date')
            ->all();

        if (empty($datesWithSchedules)) {
            return [
                'has_pending' => false,
                'affected_dates' => [],
                'conflicts' => [],
                'summary' => 'Nenhuma escala futura necessita de recálculo.',
            ];
        }

        $conflictsByDate = [];

        foreach ($datesWithSchedules as $date) {
            $dateConflicts = $this->evaluateDayIntegrity($schoolId, $date);
            if (! empty($dateConflicts)) {
                $conflictsByDate[$date] = $dateConflicts;
            }
        }

        $affectedDates = array_keys($conflictsByDate);

        return [
            'has_pending' => ! empty($affectedDates),
            'affected_dates' => $affectedDates,
            'conflicts' => $conflictsByDate,
            'summary' => empty($affectedDates)
                ? 'Todas as escalas futuras estão íntegras e em conformidade.'
                : count($affectedDates) . ' dia(s) com escalas geradas requerem recálculo devido a ausências ou quebra de regras.',
        ];
    }

    /**
     * Verifica se uma alteração pontual (nova ausência, exceção ou edição de regra)
     * tem impacto em escalas já geradas na escola para datas >= TODAY.
     *
     * @return array<string, mixed>
     */
    public function checkImpact(
        string $schoolId,
        string $startDate,
        ?string $endDate = null,
        string $eventType = 'absence',
        ?string $assistantId = null
    ): array {
        $today = CarbonImmutable::today()->toDateString();
        $from = max($startDate, $today);
        $to = $endDate ? max($endDate, $from) : $from;

        // Se o evento é totalmente no passado, não afeta escalas futuras
        if ($endDate && $endDate < $today) {
            return [
                'has_impact' => false,
                'requires_recalculation' => false,
                'affected_dates' => [],
                'conflicts' => [],
                'message' => 'Evento em data passada. Sem impacto em escalas futuras.',
            ];
        }

        // Verificar se existem escalas geradas no intervalo relevante
        $datesWithSchedules = ScheduleEntry::query()
            ->whereHas('schedule', fn ($q) => $q->where('school_id', $schoolId))
            ->whereBetween('date', [$from, $to])
            ->distinct()
            ->orderBy('date')
            ->pluck('date')
            ->all();

        if (empty($datesWithSchedules)) {
            return [
                'has_impact' => false,
                'requires_recalculation' => false,
                'affected_dates' => [],
                'conflicts' => [],
                'message' => 'Nenhuma escala gerada no intervalo de datas desta alteração.',
            ];
        }

        $conflictsByDate = [];
        foreach ($datesWithSchedules as $date) {
            $conflicts = $this->evaluateDayIntegrity($schoolId, $date, $assistantId);
            if (! empty($conflicts)) {
                $conflictsByDate[$date] = $conflicts;
            }
        }

        $affectedDates = array_keys($conflictsByDate);

        // Se foi marcada uma falta a um assistente que já tinha entradas de horário nesses dias,
        // isso obriga SEMPRE a recálculo (mesmo que ainda não tenha quebrado o mínimo de uma regra,
        // o assistente não pode continuar atribuído a tarefas na escala).
        $hasImpact = ! empty($affectedDates);
        if (! $hasImpact && $assistantId !== null && in_array($eventType, ['absence', 'exception'], true)) {
            $hasEntries = ScheduleEntry::query()
                ->whereHas('schedule', fn ($q) => $q->where('school_id', $schoolId)->where('assistant_id', $assistantId))
                ->whereBetween('date', [$from, $to])
                ->exists();

            if ($hasEntries) {
                $hasImpact = true;
                $affectedDates = $datesWithSchedules;
                $conflictsByDate[$from][] = [
                    'date' => $from,
                    'type' => 'assigned_assistant_impacted',
                    'message' => 'O assistente tem tarefas atribuídas na escala para as quais foi registada indisponibilidade/ausência.',
                ];
            }
        }

        return [
            'has_impact' => $hasImpact,
            'requires_recalculation' => $hasImpact,
            'affected_dates' => $affectedDates,
            'conflicts' => $conflictsByDate,
            'message' => $hasImpact
                ? 'Esta alteração afeta a escala gerada em ' . count($affectedDates) . ' dia(s). É necessário recalcular o horário.'
                : 'A alteração não causou conflitos com a escala existente.',
        ];
    }

    /**
     * Avalia a integridade de um dia específico com escala gerada.
     *
     * @return list<array<string, mixed>>
     */
    private function evaluateDayIntegrity(string $schoolId, string $date, ?string $targetAssistantId = null): array
    {
        $conflicts = [];
        $dayInfo = $this->operatingHoursResolver->forDate($schoolId, $date);

        // A. Verificar se a escola está encerrada
        if (! ($dayInfo['is_open'] ?? true)) {
            $conflicts[] = [
                'date' => $date,
                'type' => 'school_closed',
                'message' => 'O estabelecimento encontra-se encerrado nesta data (' . ($dayInfo['reason'] ?? $dayInfo['source'] ?? 'Encerramento') . '), mas existem escalas geradas.',
            ];

            return $conflicts;
        }

        // B. Carregar entradas geradas para a data nesta escola
        $entries = ScheduleEntry::query()
            ->with(['schedule.assistant.user', 'activityType', 'scheduleRule'])
            ->whereHas('schedule', fn ($q) => $q->where('school_id', $schoolId))
            ->where('date', $date)
            ->get();

        if ($entries->isEmpty()) {
            return [];
        }

        // Carregar ausências ativas para a data nesta escola
        $absences = Absence::query()
            ->with('assistant.user')
            ->whereHas('assistant', fn ($q) => $q->where('school_id', $schoolId))
            ->whereDate('valid_from', '<=', $date)
            ->where(fn ($q) => $q->whereNull('valid_until')->orWhereDate('valid_until', '>=', $date))
            ->get();

        // 1. Verificar conflito direto: assistente atribuído com ausência sobreposta
        $absentAssistantsInEntries = [];
        foreach ($entries as $entry) {
            $assistant = $entry->schedule?->assistant;
            if (! $assistant) {
                continue;
            }

            $entryStart = $this->toMinutes($entry->start_time);
            $entryEnd = $this->toMinutes($entry->end_time);

            $assistantAbsences = $absences->where('assistant_id', $assistant->id);
            foreach ($assistantAbsences as $abs) {
                $aStart = $abs->start_time ? $this->toMinutes(substr((string) $abs->start_time, 0, 5)) : 0;
                $aEnd = $abs->end_time ? $this->toMinutes(substr((string) $abs->end_time, 0, 5)) : 1440;

                if ($this->intervalsOverlap($entryStart, $entryEnd, $aStart, $aEnd)) {
                    $userName = $assistant->user ? trim($assistant->user->first_name . ' ' . $assistant->user->last_name) : 'Assistente';
                    $conflictKey = "{$assistant->id}_{$entry->id}";
                    if (! isset($absentAssistantsInEntries[$conflictKey])) {
                        $absentAssistantsInEntries[$conflictKey] = true;
                        $conflicts[] = [
                            'date' => $date,
                            'type' => 'assigned_assistant_absent',
                            'assistant_id' => $assistant->id,
                            'assistant_name' => $userName,
                            'start_time' => substr((string) $entry->start_time, 0, 5),
                            'end_time' => substr((string) $entry->end_time, 0, 5),
                            'activity_name' => $entry->activity_name ?? $entry->activityType?->name ?? 'Atividade',
                            'message' => "O assistente {$userName} tem tarefa '{$entry->activity_name}' agendada ({$entry->start_time} - {$entry->end_time}), mas encontra-se ausente nesta data.",
                        ];
                    }
                }
            }
        }

        // 2. Verificar regras obrigatórias ativas nesta data
        $carbonDate = CarbonImmutable::parse($date);
        $weekday = $carbonDate->dayOfWeekIso - 1; // 0 = Seg ... 6 = Dom

        $rules = ScheduleRule::query()
            ->with(['activityType', 'assistants.user'])
            ->where('school_id', $schoolId)
            ->where('type', 'mandatory')
            ->whereDate('valid_from', '<=', $date)
            ->where(fn ($q) => $q->whereNull('valid_until')->orWhereDate('valid_until', '>=', $date))
            ->get()
            ->filter(fn (ScheduleRule $r) => in_array($weekday, array_map('intval', $r->days_of_week ?? []), true));

        foreach ($rules as $rule) {
            $ruleStart = $this->toMinutes($rule->period_start);
            $ruleEnd = $this->toMinutes($rule->period_end);

            // Assistentes atribuídos a esta regra
            $ruleEntries = $entries->filter(function (ScheduleEntry $e) use ($rule, $ruleStart, $ruleEnd) {
                if ($e->schedule_rule_id === $rule->id) {
                    return true;
                }
                if ($e->activity_type_id === $rule->activity_type_id) {
                    $eStart = $this->toMinutes($e->start_time);
                    $eEnd = $this->toMinutes($e->end_time);

                    return $this->intervalsOverlap($ruleStart, $ruleEnd, $eStart, $eEnd);
                }

                return false;
            });

            // Filtrar assistentes que NÃO estão ausentes durante este período
            $availableAssistants = [];
            foreach ($ruleEntries as $re) {
                $assId = $re->schedule?->assistant_id;
                if (! $assId || in_array($assId, $availableAssistants, true)) {
                    continue;
                }

                $isAbsent = $absences->where('assistant_id', $assId)->contains(function (Absence $abs) use ($ruleStart, $ruleEnd) {
                    $aStart = $abs->start_time ? $this->toMinutes(substr((string) $abs->start_time, 0, 5)) : 0;
                    $aEnd = $abs->end_time ? $this->toMinutes(substr((string) $abs->end_time, 0, 5)) : 1440;

                    return $this->intervalsOverlap($ruleStart, $ruleEnd, $aStart, $aEnd);
                });

                if (! $isAbsent) {
                    $availableAssistants[] = $assId;
                }
            }

            $effectiveCount = count($availableAssistants);
            if ($effectiveCount < $rule->min) {
                $actName = $rule->activityType?->name ?? 'Regra Obrigatória';
                $pStart = substr((string) $rule->period_start, 0, 5);
                $pEnd = substr((string) $rule->period_end, 0, 5);

                $conflicts[] = [
                    'date' => $date,
                    'type' => 'mandatory_rule_breach',
                    'rule_id' => $rule->id,
                    'activity_name' => $actName,
                    'period_start' => $pStart,
                    'period_end' => $pEnd,
                    'period' => "{$pStart} às {$pEnd}",
                    'required' => (int) $rule->min,
                    'covered' => $effectiveCount,
                    'missing' => (int) $rule->min - $effectiveCount,
                    'message' => "A regra obrigatória '{$actName}' ({$pStart} às {$pEnd}) exige no mínimo {$rule->min} assistente(s), mas apenas {$effectiveCount} está(ão) atribuído(s) e disponível(eis).",
                ];
            }
        }

        return $conflicts;
    }

    private function toMinutes(string $time): int
    {
        $parts = explode(':', $time);

        return ((int) $parts[0] * 60) + (int) ($parts[1] ?? 0);
    }

    private function intervalsOverlap(int $s1, int $e1, int $s2, int $e2): bool
    {
        return max($s1, $s2) < min($e1, $e2);
    }
}
