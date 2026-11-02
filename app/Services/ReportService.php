<?php

namespace App\Services;

use App\Models\Absences\Absence;
use App\Models\Assistants\Assistant;
use App\Models\Assistants\AssistantException;
use App\Models\Assistants\AssistantTemporaryAssignments;
use App\Models\Schedules\AssistantScheduleProfile;
use App\Models\Schedules\Schedule;
use App\Models\Schedules\ScheduleRule;
use App\Models\Schools\School;
use Illuminate\Support\Collection;

/** Read-only reports: never generate, publish or modify schedules. */
class ReportService
{
    public function __construct(
        private readonly OperatingHoursResolver $calendar,
        private readonly ScheduleGenerationService $shifts,
    ) {}

    public function build(string $schoolId, string $from, string $to): array
    {
        $school = School::findOrFail($schoolId);
        $days = $this->calendar->forRange($schoolId, $from, $to);
        $schedules = Schedule::with(['scheduleEntries' => fn ($q) => $q->whereBetween('date', [$from, $to])])
            ->where('school_id', $schoolId)->where('status', 'published')
            ->whereDate('week_start', '<=', $to)->whereDate('week_end', '>=', $from)->get();
        $transfers = AssistantTemporaryAssignments::where(fn ($q) => $q
            ->where('origin_school_id', $schoolId)->orWhere('destination_school_id', $schoolId))
            ->whereDate('valid_from', '<=', $to)
            ->where(fn ($q) => $q->whereNull('valid_until')->orWhereDate('valid_until', '>=', $from))->get();
        // Include published participants and incoming transfers as well as home-school staff.
        $ids = $schedules->pluck('assistant_id')->merge($transfers->pluck('assistant_id'))->unique();
        $assistants = Assistant::with('user')->where(fn ($q) => $q
            ->where('school_id', $schoolId)->orWhereIn('id', $ids))->orderBy('id')->get()->keyBy('id');
        $profiles = AssistantScheduleProfile::with('shifts.shiftDays')->whereIn('assistant_id', $assistants->keys())
            ->whereDate('valid_from', '<=', $to)->orderByDesc('valid_from')->get()->groupBy('assistant_id');
        $exceptions = AssistantException::whereIn('assistant_id', $assistants->keys())
            ->whereDate('valid_from', '<=', $to)->orderByDesc('valid_from')->orderBy('id')->get()
            ->each(function (AssistantException $exception) {
                // Read older installations without changing their schema or data.
                if (! array_key_exists('valid_until', $exception->getAttributes())) {
                    $exception->setAttribute('valid_until', $exception->getRawOriginal('valid_untill'));
                }
            })->filter(fn ($e) => $e->valid_until === null || $e->valid_until->toDateString() >= $from)
            ->groupBy('assistant_id');
        $absences = Absence::with('absenceType')->whereIn('assistant_id', $assistants->keys())
            ->whereDate('valid_from', '<=', $to)
            ->where(fn ($q) => $q->whereNull('valid_until')->orWhereDate('valid_until', '>=', $from))
            ->orderBy('valid_from')->orderBy('id')->get()
            ->filter(fn ($absence) => collect($days)->contains(fn ($day) => $this->activeOn($absence, $day['date'])
                && $this->belongsOn($assistants->get($absence->assistant_id), $schoolId, $day['date'], $transfers)));
        $rules = ScheduleRule::with('activityType')->where('school_id', $schoolId)->where('type', 'mandatory')
            ->whereDate('valid_from', '<=', $to)
            ->where(fn ($q) => $q->whereNull('valid_until')->orWhereDate('valid_until', '>=', $from))
            ->orderBy('id')->get();

        $snapshot = ['profiles' => $profiles, 'exceptions' => $exceptions];
        $entriesByDate = $schedules->flatMap(fn ($s) => $s->scheduleEntries->map(fn ($e) => [
            'assistant_id' => $s->assistant_id, 'rule_id' => $e->schedule_rule_id,
            'activity_id' => $e->activity_type_id, 'date' => substr((string) $e->date, 0, 10),
            'start' => $this->minutes($e->start_time), 'end' => $this->minutes($e->end_time),
        ]))->groupBy('date');

        $daily = [];
        $gaps = [];
        $workload = [];
        $absenceRows = [];
        $types = [];
        $affected = [];
        $exceptionAssistants = [];
        foreach ($assistants as $assistant) {
            $workload[$assistant->id] = [
                'assistant_id' => $assistant->id, 'assistant_name' => $this->name($assistant),
                'expected_minutes' => 0, 'assigned_minutes' => 0, 'absence_minutes' => 0,
            ];
        }
        foreach ($absences as $absence) {
            $absenceRows[$absence->id] = [
                'id' => $absence->id, 'assistant_id' => $absence->assistant_id,
                'assistant_name' => $this->name($assistants->get($absence->assistant_id)),
                'absence_type_id' => $absence->absence_type_id,
                'absence_type' => $absence->absenceType?->name,
                'start_date' => $absence->valid_from->toDateString(),
                'end_date' => $absence->valid_until?->toDateString(),
                'status' => $absence->status, 'affected_workdays' => 0,
                'absence_minutes' => 0, 'has_estimated_lunch' => false, 'days_without_profile' => 0,
            ];
        }

        foreach ($days as $day) {
            $date = $day['date'];
            $published = $schedules->contains(fn ($s) => $s->week_start->toDateString() <= $date && $s->week_end->toDateString() >= $date);
            $entries = $entriesByDate->get($date, collect());
            $coverage = ['required_minutes' => null, 'covered_minutes' => null, 'uncovered_minutes' => null, 'coverage_rate' => null];
            if ($day['is_open'] && $published) {
                $coverage = $this->coverage($day, $rules, $entries, $gaps);
            }
            $daily[] = array_merge([
                'date' => $date, 'is_open' => $day['is_open'],
                'has_published_schedule' => $published,
                'status' => ! $day['is_open'] ? 'closed' : ($published ? 'published' : 'no_published_schedule'),
            ], $coverage);

            foreach ($entries->groupBy('assistant_id') as $assistantId => $assigned) {
                if (isset($workload[$assistantId])) {
                    $workload[$assistantId]['assigned_minutes'] += $this->unionMinutes($assigned->map(fn ($e) => [$e['start'], $e['end']])->all());
                }
            }
            foreach ($assistants as $assistant) {
                if (! $this->belongsOn($assistant, $schoolId, $date, $transfers)) {
                    continue;
                }
                $activeExceptions = $exceptions->get($assistant->id, collect())->filter(fn ($e) => $this->activeOn($e, $date)
                    && (! $e->days || in_array($day['day_of_week'], array_map('intval', $e->days), true)));
                if ($activeExceptions->isNotEmpty()) {
                    $exceptionAssistants[$assistant->id] = true;
                }
                if (! $day['is_open']) {
                    continue;
                }
                $effective = $this->shifts->resolveEffectiveShift($snapshot, $assistant->id, $date, $day['day_of_week'], [
                    'opening' => $this->minutes($day['opening_time']), 'closing' => $this->minutes($day['closing_time']),
                    'opening_str' => $day['opening_time'], 'closing_str' => $day['closing_time'],
                ]);
                if ($effective['status'] !== 'ok') {
                    $hasProfile = $profiles->get($assistant->id, collect())->contains(fn ($p) => $this->activeOn($p, $date));
                    if (! $hasProfile && $activeExceptions->isEmpty()) {
                        foreach ($absences->where('assistant_id', $assistant->id) as $absence) {
                            if ($this->activeOn($absence, $date)) {
                                $absenceRows[$absence->id]['days_without_profile']++;
                            }
                        }
                    }

                    continue;
                }
                $shift = $effective['schedule'];
                $workload[$assistant->id]['expected_minutes'] += $shift['effective_work_minutes'];
                $allAbsenceIntervals = [];
                foreach ($absences->where('assistant_id', $assistant->id) as $absence) {
                    if (! $this->activeOn($absence, $date)) {
                        continue;
                    }
                    $start = max($shift['entry_min'], $absence->start_time ? $this->minutes($absence->start_time) : $shift['entry_min']);
                    $end = min($shift['exit_min'], $absence->end_time ? $this->minutes($absence->end_time) : $shift['exit_min']);
                    $intervals = $this->withoutLunch($start, $end, $shift);
                    $minutes = $this->unionMinutes($intervals);
                    if ($minutes === 0) {
                        continue;
                    }
                    $row = &$absenceRows[$absence->id];
                    $row['absence_minutes'] += $minutes;
                    $row['affected_workdays']++;
                    $row['has_estimated_lunch'] = $row['has_estimated_lunch'] || ($shift['lunch_enabled'] && ($absence->start_time || $absence->end_time));
                    unset($row);
                    $allAbsenceIntervals = array_merge($allAbsenceIntervals, $intervals);
                    $affected[$assistant->id] = true;
                    $key = $absence->absence_type_id;
                    $types[$key] ??= ['absence_type_id' => $key, 'name' => $absence->absenceType?->name, 'absence_minutes' => 0];
                    $types[$key]['absence_minutes'] += $minutes;
                }
                $workload[$assistant->id]['absence_minutes'] += $this->unionMinutes($allAbsenceIntervals);
            }
        }

        $required = array_sum(array_column($daily, 'required_minutes'));
        $covered = array_sum(array_column($daily, 'covered_minutes'));
        $absenceTotal = array_sum(array_column($workload, 'absence_minutes'));

        return [
            'school' => ['id' => $school->id, 'name' => $school->name],
            'period' => ['start_date' => $from, 'end_date' => $to],
            'summary' => [
                'coverage_rate' => $required > 0 ? round($covered * 100 / $required, 2) : null,
                'required_minutes' => $required, 'covered_minutes' => $covered,
                'uncovered_minutes' => $required - $covered,
                'absence_minutes' => $absenceTotal, 'affected_assistants' => count($affected),
                'assistants_with_exceptions' => count($exceptionAssistants),
                'open_days_without_published_schedule' => collect($daily)->where('status', 'no_published_schedule')->count(),
                'absence_records_without_profile' => collect($absenceRows)->where('days_without_profile', '>', 0)->count(),
            ],
            'daily_coverage' => $daily,
            'absences_by_type' => array_values($types),
            'workload_by_assistant' => array_values($workload),
            'coverage_gaps' => $gaps,
            'absences' => array_values($absenceRows),
            'methodology' => [
                'unit' => 'minutes',
                'coverage_basis' => 'published_assignments_against_current_mandatory_rules',
                'coverage_is_historical_snapshot' => false,
                'absence_basis' => 'current_profiles_and_exceptions_clipped_to_school_hours',
                'partial_absence_lunch_assumption' => 'earliest_allowed_lunch_start',
                'overlapping_absences' => 'summary_and_workload_use_union; rows_and_types_count_each_record',
                'missing_profile' => 'absence_record_returned_with_zero_calculable_minutes',
            ],
        ];
    }

    private function coverage(array $day, Collection $rules, Collection $entries, array &$gaps): array
    {
        $required = 0;
        $covered = 0;
        foreach ($rules as $rule) {
            if (! $this->activeOn($rule, $day['date']) || ! in_array($day['day_of_week'], array_map('intval', $rule->days_of_week ?? []), true)) {
                continue;
            }
            $start = max($this->minutes($rule->period_start), $this->minutes($day['opening_time']));
            $end = min($this->minutes($rule->period_end), $this->minutes($day['closing_time']));
            if ($end <= $start || $rule->min <= 0) {
                continue;
            }
            $matching = $entries->filter(fn ($e) => $e['rule_id'] === $rule->id
                || ($e['rule_id'] === null && $e['activity_id'] === $rule->activity_type_id));
            $points = [$start, $end];
            foreach ($matching as $entry) {
                if ($entry['start'] < $end && $entry['end'] > $start) {
                    $points[] = max($start, $entry['start']);
                    $points[] = min($end, $entry['end']);
                }
            }
            $points = array_values(array_unique($points));
            sort($points);
            $lastGap = null;
            for ($i = 0; $i < count($points) - 1; $i++) {
                [$a, $b] = [$points[$i], $points[$i + 1]];
                $assigned = $matching->filter(fn ($e) => $e['start'] <= $a && $e['end'] >= $b)->pluck('assistant_id')->unique()->count();
                $required += ($b - $a) * $rule->min;
                $covered += ($b - $a) * min($assigned, $rule->min);
                if ($assigned < $rule->min) {
                    if ($lastGap !== null && $gaps[$lastGap]['end_time'] === $this->time($a) && $gaps[$lastGap]['assigned_assistants'] === $assigned) {
                        $gaps[$lastGap]['end_time'] = $this->time($b);
                        $gaps[$lastGap]['uncovered_minutes'] += ($b - $a) * ($rule->min - $assigned);
                    } else {
                        $gaps[] = [
                            'date' => $day['date'], 'schedule_rule_id' => $rule->id,
                            'activity_type_id' => $rule->activity_type_id, 'activity_name' => $rule->activityType?->name,
                            'start_time' => $this->time($a), 'end_time' => $this->time($b),
                            'required_assistants' => $rule->min, 'assigned_assistants' => $assigned,
                            'missing_assistants' => $rule->min - $assigned,
                            'uncovered_minutes' => ($b - $a) * ($rule->min - $assigned),
                        ];
                        $lastGap = array_key_last($gaps);
                    }
                } else {
                    $lastGap = null;
                }
            }
        }

        return [
            'required_minutes' => $required, 'covered_minutes' => $covered,
            'uncovered_minutes' => $required - $covered,
            'coverage_rate' => $required > 0 ? round($covered * 100 / $required, 2) : null,
        ];
    }

    private function activeOn(object $record, string $date): bool
    {
        return $record->valid_from->toDateString() <= $date
            && ($record->valid_until === null || $record->valid_until->toDateString() >= $date);
    }

    private function belongsOn(Assistant $assistant, string $schoolId, string $date, Collection $transfers): bool
    {
        $active = $transfers->where('assistant_id', $assistant->id)->filter(fn ($t) => $this->activeOn($t, $date));
        if ($active->contains('destination_school_id', $schoolId)) {
            return true;
        }

        return $assistant->school_id === $schoolId && ! $active->contains('origin_school_id', $schoolId);
    }

    private function withoutLunch(int $start, int $end, array $shift): array
    {
        if ($end <= $start) {
            return [];
        }
        if (! $shift['lunch_enabled']) {
            return [[$start, $end]];
        }
        $lunchStart = $shift['lunch_start_min'];
        $lunchEnd = $lunchStart + $shift['lunch_duration'];

        return array_values(array_filter([
            [$start, min($end, $lunchStart)], [max($start, $lunchEnd), $end],
        ], fn ($range) => $range[1] > $range[0]));
    }

    private function unionMinutes(array $ranges): int
    {
        usort($ranges, fn ($a, $b) => $a[0] <=> $b[0]);
        $total = 0;
        $lastEnd = -1;
        foreach ($ranges as [$start, $end]) {
            $total += max(0, $end - max($start, $lastEnd));
            $lastEnd = max($lastEnd, $end);
        }

        return $total;
    }

    private function minutes(?string $time): int
    {
        return (int) substr((string) $time, 0, 2) * 60 + (int) substr((string) $time, 3, 2);
    }

    private function time(int $minutes): string
    {
        return sprintf('%02d:%02d', intdiv($minutes, 60), $minutes % 60);
    }

    private function name(?Assistant $assistant): string
    {
        return $assistant?->user?->name ?? 'Assistente indisponível';
    }
}
