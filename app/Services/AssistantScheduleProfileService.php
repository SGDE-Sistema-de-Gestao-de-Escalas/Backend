<?php

namespace App\Services;

use App\Models\Schedules\AssistantScheduleProfile;
use Illuminate\Support\Facades\DB;

class AssistantScheduleProfileService
{
    /**
     * Cria um perfil de horário padrão e respetivos turnos e dias da semana.
     */
    public function create(array $data): AssistantScheduleProfile
    {
        return DB::transaction(function () use ($data) {
            $profile = AssistantScheduleProfile::create([
                'assistant_id' => $data['assistant_id'],
                'type' => $data['type'],
                'rotation_period' => $data['rotation_period'] ?? null,
                'starts_with' => $data['starts_with'] ?? null,
                'valid_from' => $data['valid_from'],
                'valid_until' => $data['valid_until'] ?? null,
            ]);

            foreach ($data['shifts'] ?? [] as $shiftData) {
                $shift = $profile->shifts()->create([
                    'shift_label' => $shiftData['shift_label'],
                    'entry_time' => $shiftData['entry_time'],
                    'exit_time' => $shiftData['exit_time'],
                    'lunch_enabled' => $shiftData['lunch_enabled'] ?? true,
                    'lunch_start' => $shiftData['lunch_start'] ?? null,
                    'lunch_end' => $shiftData['lunch_end'] ?? null,
                    'lunch_duration_minutes' => $shiftData['lunch_duration_minutes'] ?? null,
                ]);

                foreach ($shiftData['days'] ?? [] as $dayOfWeek) {
                    $shift->shiftDays()->create([
                        'day_of_week' => (int) $dayOfWeek,
                    ]);
                }
            }

            return $profile->load(['shifts.shiftDays']);
        });
    }

    /**
     * Atualiza um perfil de horário padrão existente.
     */
    public function update(AssistantScheduleProfile $profile, array $data): AssistantScheduleProfile
    {
        return DB::transaction(function () use ($profile, $data) {
            $profile->update([
                'type' => $data['type'] ?? $profile->type,
                'rotation_period' => $data['rotation_period'] ?? $profile->rotation_period,
                'starts_with' => $data['starts_with'] ?? $profile->starts_with,
                'valid_from' => $data['valid_from'] ?? $profile->valid_from,
                'valid_until' => array_key_exists('valid_until', $data) ? $data['valid_until'] : $profile->valid_until,
            ]);

            if (isset($data['shifts'])) {
                // Remove os shiftDays associados a cada turno antes de remover os turnos
                foreach ($profile->shifts as $existingShift) {
                    $existingShift->shiftDays()->delete();
                }
                $profile->shifts()->delete();

                foreach ($data['shifts'] as $shiftData) {
                    $shift = $profile->shifts()->create([
                        'shift_label' => $shiftData['shift_label'],
                        'entry_time' => $shiftData['entry_time'],
                        'exit_time' => $shiftData['exit_time'],
                        'lunch_enabled' => $shiftData['lunch_enabled'] ?? true,
                        'lunch_start' => $shiftData['lunch_start'] ?? null,
                        'lunch_end' => $shiftData['lunch_end'] ?? null,
                        'lunch_duration_minutes' => $shiftData['lunch_duration_minutes'] ?? null,
                    ]);

                    foreach ($shiftData['days'] ?? [] as $dayOfWeek) {
                        $shift->shiftDays()->create([
                            'day_of_week' => (int) $dayOfWeek,
                        ]);
                    }
                }
            }

            return $profile->fresh()->load(['shifts.shiftDays']);
        });
    }

    /**
     * Remove um perfil de horário padrão e as suas dependências.
     */
    public function delete(AssistantScheduleProfile $profile): void
    {
        DB::transaction(function () use ($profile) {
            foreach ($profile->shifts as $shift) {
                $shift->shiftDays()->delete();
            }
            $profile->shifts()->delete();
            $profile->delete();
        });
    }
}

