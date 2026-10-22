<?php

namespace App\Services;

use App\Models\Absences\Absence;
use App\Models\Assistants\AssistantException;
use App\Models\Schedules\ScheduleEntry;
use App\Models\Schedules\ScheduleRule;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * Read-only selection for one occurrence. Does not generate or publish schedules.
 * Working periods must already account for shifts, rotation and lunch breaks.
 */
class ScheduleRuleAllocationService
{
    /**
     * @param  array<string, list<array{start: string, end: string}>>  $workingPeriods  Assistant UUID => periods for $date.
     * @param  list<array{assistant_id: string, date: string, start: string, end: string}>  $reservations  Earlier selections in the same generation run.
     * @return array{schedule_rule_id: string, date: string, assistant_ids: array, required: int, missing: int, complete: bool, skipped: array}
     */
    public function allocate(ScheduleRule $rule, string $date, array $workingPeriods, array $reservations = []): array
    {
        Validator::make(compact('date', 'workingPeriods', 'reservations'), [
            'date' => ['required', 'date_format:Y-m-d'],
            'workingPeriods' => ['array'],
            'workingPeriods.*' => ['array', 'list'],
            'workingPeriods.*.*' => ['array:start,end'],
            'workingPeriods.*.*.start' => ['required', 'date_format:H:i'],
            'workingPeriods.*.*.end' => ['required', 'date_format:H:i', 'after:workingPeriods.*.*.start'],
            'reservations' => ['array', 'list'],
            'reservations.*' => ['array:assistant_id,date,start,end'],
            'reservations.*.assistant_id' => ['required', 'uuid'],
            'reservations.*.date' => ['required', 'date_format:Y-m-d'],
            'reservations.*.start' => ['required', 'date_format:H:i'],
            'reservations.*.end' => ['required', 'date_format:H:i', 'after:reservations.*.start'],
        ])->validate();

        // Read persisted configuration, rather than possibly stale loaded relations.
        $rule = ScheduleRule::with('activityType')->findOrFail($rule->id);
        $weekday = CarbonImmutable::parse($date)->dayOfWeekIso - 1;
        if (! in_array($weekday, $rule->days_of_week ?? [], true)) {
            throw ValidationException::withMessages(['days_of_week' => 'A regra não se aplica a este dia da semana.']);
        }
        if ($rule->type !== 'mandatory') {
            throw ValidationException::withMessages(['rule' => 'A regra não se aplica à data ou ao tipo pedido.']);
        }
        if ($date < $rule->valid_from->format('Y-m-d')
            || ($rule->valid_until !== null && $date > $rule->valid_until->format('Y-m-d'))) {
            throw ValidationException::withMessages(['rule' => 'A regra não se encontra em vigor nesta data.']);
        }
        if (! $rule->activityType?->active || $rule->activityType->school_id !== $rule->school_id) {
            throw ValidationException::withMessages(['activity_type_id' => 'A atividade não está disponível nesta escola.']);
        }
        if ($rule->activityType->is_fallback) {
            throw ValidationException::withMessages(['activity_type_id' => 'A atividade base de preenchimento automático não pode ser usada em regras de horário.']);
        }
        $start = substr($rule->period_start, 0, 5);
        $end = substr($rule->period_end, 0, 5);
        if ($rule->min < 1 || $end <= $start) {
            throw ValidationException::withMessages(['rule' => 'A regra tem um mínimo ou período inválido.']);
        }

        $candidates = $rule->assistants()->withTrashed()->with('user')->orderBy('assistants.id')->get();
        $ids = $candidates->modelKeys();
        $absent = Absence::whereIn('assistant_id', $ids)->whereDate('valid_from', '<=', $date)
            ->where(fn ($query) => $query->whereNull('valid_until')->orWhereDate('valid_until', '>=', $date))
            ->pluck('assistant_id')->flip();
        $exceptions = AssistantException::whereIn('assistant_id', $ids)->whereDate('valid_from', '<=', $date)
            ->where(fn ($query) => $query->whereNull('valid_until')->orWhereDate('valid_until', '>=', $date))
            ->get()->groupBy('assistant_id');

        // Every existing entry occupies time, regardless of school or lock status.
        $occupied = ScheduleEntry::query()->join('schedules', 'schedules.id', '=', 'schedule_entries.schedule_id')
            ->whereNull('schedules.deleted_at')->whereIn('schedules.assistant_id', $ids)
            ->whereDate('schedule_entries.date', $date)
            ->whereTime('schedule_entries.start_time', '<', $end.':00')
            ->whereTime('schedule_entries.end_time', '>', $start.':00')
            ->pluck('schedules.assistant_id')->flip();

        $selected = [];
        $skipped = [];
        foreach ($candidates as $assistant) {
            $reason = null;
            if ($assistant->trashed() || $assistant->is_anonymized || ! $assistant->user
                || $assistant->user->trashed() || ! $assistant->user->is_active || $assistant->user->anonymized_at) {
                $reason = 'inactive_assistant';
            } elseif ($assistant->school_id !== $rule->school_id) {
                $reason = 'different_school';
            } elseif ($absent->has($assistant->id)) {
                $reason = 'absence';
            } elseif ($occupied->has($assistant->id) || collect($reservations)->contains(fn ($slot) => $slot['assistant_id'] === $assistant->id && $slot['date'] === $date
                && $this->overlaps($start, $end, $slot['start'], $slot['end']))) {
                $reason = 'overlapping_assignment';
            } elseif ($exceptions->get($assistant->id, collect())->contains(fn ($exception) => $this->overlaps($start, $end, $exception->getRawOriginal('start_time') ?? '00:00:00', $exception->getRawOriginal('end_time') ?? '24:00:00'))) {
                // Exception types are free text today: do not assume they permit work.
                $reason = 'unresolved_exception';
            } elseif (! $this->covers($workingPeriods[$assistant->id] ?? [], $start, $end)) {
                $reason = 'outside_working_period';
            }

            if ($reason !== null) {
                $skipped[] = ['assistant_id' => $assistant->id, 'priority' => (int) $assistant->pivot->priority, 'reason' => $reason];

                continue;
            }
            $selected[] = $assistant->id;
            if (count($selected) === $rule->min) {
                break;
            }
        }

        $missing = max(0, $rule->min - count($selected));

        return [
            'schedule_rule_id' => $rule->id, 'date' => $date,
            'assistant_ids' => $selected, 'required' => $rule->min,
            'missing' => $missing, 'complete' => $missing === 0, 'skipped' => $skipped,
        ];
    }

    private function overlaps(string $start, string $end, string $otherStart, string $otherEnd): bool
    {
        return substr($otherStart, 0, 5) < $end && substr($otherEnd, 0, 5) > $start;
    }

    private function covers(array $periods, string $start, string $end): bool
    {
        usort($periods, fn ($a, $b) => strcmp($a['start'], $b['start']));
        $coveredUntil = $start;
        foreach ($periods as $period) {
            if ($period['start'] > $coveredUntil) {
                break;
            }
            $coveredUntil = max($coveredUntil, $period['end']);
            if ($coveredUntil >= $end) {
                return true;
            }
        }

        return false;
    }
}
