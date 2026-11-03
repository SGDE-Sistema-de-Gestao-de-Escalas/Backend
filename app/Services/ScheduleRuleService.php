<?php

namespace App\Services;

use App\Models\Schedules\Schedule;
use App\Models\Schedules\ScheduleEntry;
use App\Models\Schedules\ScheduleRule;
use App\Models\System\ActivityType;
use Carbon\CarbonImmutable;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ScheduleRuleService
{
    public function create(array $data, string $schoolId): ScheduleRule
    {
        $data = $this->normalizeDays($data);

        return DB::transaction(function () use ($data, $schoolId) {
            $this->validateActivityType($data['activity_type_id'], $schoolId);
            $this->validateSelection($data['type'], (int) $data['min'], $data['assistant_ids']);
            $this->validateValidity($data['valid_from'], $data['valid_until'] ?? null);
            $rule = ScheduleRule::create(Arr::except($data, ['assistant_ids', 'school_id']) + ['school_id' => $schoolId]);
            $this->syncAssistants($rule, $data['assistant_ids']);

            return $rule->load('assistants.user');
        });
    }

    public function delete(ScheduleRule $rule, bool $confirmed = false): array
    {
        return DB::transaction(function () use ($rule, $confirmed) {
            $rule = ScheduleRule::whereKey($rule->id)->lockForUpdate()->firstOrFail();
            $today = CarbonImmutable::today()->toDateString();
            $entries = ScheduleEntry::where('schedule_rule_id', $rule->id)->whereHas('schedule');

            if ((clone $entries)->where('date', '<', $today)->exists()) {
                throw ValidationException::withMessages([
                    'schedule_rule' => 'Não é possível eliminar uma regra que já foi usada em horários passados. Defina a data de fim da regra.',
                ]);
            }

            $upcoming = (clone $entries)->where('date', '>=', $today)->get(['schedule_id', 'date']);
            $impact = $upcoming->isEmpty() ? null : $this->deletionImpact($upcoming);

            if ($impact !== null && ! $confirmed) {
                return ['deleted' => false, 'impact' => $impact];
            }

            if ($impact !== null) {
                Schedule::whereIn('id', $upcoming->pluck('schedule_id')->unique())
                    ->where('status', 'published')
                    ->update(['status' => 'draft', 'published_at' => null]);
            }

            $rule->delete();

            return ['deleted' => true, 'impact' => $impact];
        });
    }

    private function deletionImpact(Collection $upcoming): array
    {
        $scheduleIds = $upcoming->pluck('schedule_id')->unique()->values();
        $dates = $upcoming->map(fn ($entry) => substr((string) $entry->date, 0, 10))->unique()->sort()->values()->all();
        $published = Schedule::whereIn('id', $scheduleIds)->where('status', 'published')->count();

        return [
            'has_impact' => true,
            'requires_recalculation' => true,
            'affected_dates' => $dates,
            'affected_schedules' => $scheduleIds->count(),
            'drafted_schedules' => $published,
            'message' => 'A eliminação desta regra afeta a escala gerada em '.count($dates).' dia(s). Os horários afetados passam a rascunho e é necessário recalcular o horário.',
        ];
    }

    public function update(ScheduleRule $rule, array $data): ScheduleRule
    {
        $data = $this->normalizeDays($data);

        return DB::transaction(function () use ($rule, $data) {
            $rule = ScheduleRule::whereKey($rule->id)->lockForUpdate()->firstOrFail();
            $rule->fill(Arr::except($data, ['assistant_ids', 'school_id']));
            $this->validateActivityType($rule->activity_type_id, $rule->school_id);
            // Recheck against the locked version when concurrent edits change the selection.
            $ids = $data['assistant_ids'] ?? $rule->assistants()->pluck('assistants.id')->all();
            $this->validateSelection($rule->type, $rule->min, $ids);
            if (substr($rule->period_end, 0, 5) <= substr($rule->period_start, 0, 5)) {
                throw ValidationException::withMessages(['period_end' => 'O fim do período deve ser posterior ao início.']);
            }
            $this->validateValidity($rule->valid_from->format('Y-m-d'), $rule->valid_until?->format('Y-m-d'));
            $rule->save();
            if (array_key_exists('assistant_ids', $data)) {
                $this->syncAssistants($rule, $data['assistant_ids']);
            }

            return $rule->load('assistants.user');
        });
    }

    private function validateActivityType(string $activityTypeId, string $schoolId): void
    {
        $activityType = ActivityType::whereKey($activityTypeId)
            ->where('school_id', $schoolId)
            ->where('active', true)
            ->first();

        if (! $activityType || $activityType->is_fallback) {
            throw ValidationException::withMessages([
                'activity_type_id' => 'A atividade base de preenchimento automático (ou inativa) não pode ser associada a regras de horário.',
            ]);
        }
    }

    private function validateValidity(string $from, ?string $until): void
    {
        if ($until !== null && $until < $from) {
            throw ValidationException::withMessages(['valid_until' => 'A data de fim deve ser igual ou posterior à data de início.']);
        }
    }

    private function normalizeDays(array $data): array
    {
        if (array_key_exists('days_of_week', $data)) {
            $data['days_of_week'] = array_map('intval', $data['days_of_week']);
            sort($data['days_of_week']);
        }

        return $data;
    }

    public static function selectionErrors(string $type, int $min, array $ids): array
    {
        $required = $min;

        if (count($ids) < $required) {
            $plural = $required === 1 ? 'assistente' : 'assistentes';

            return [
                'assistant_ids' => "Selecione pelo menos {$required} {$plural} para cumprir a dotação mínima da regra.",
            ];
        }

        return [];
    }

    private function validateSelection(string $type, int $min, array $ids): void
    {
        $errors = self::selectionErrors($type, $min, $ids);
        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
    }

    private function syncAssistants(ScheduleRule $rule, array $ids): void
    {
        $sync = [];
        foreach ($ids as $index => $id) {
            $sync[$id] = ['priority' => $index + 1];
        }
        $rule->assistants()->sync($sync);
    }
}
