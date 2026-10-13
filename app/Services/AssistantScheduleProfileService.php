<?php

namespace App\Services;

use App\Models\Schedules\AssistantScheduleProfile;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class AssistantScheduleProfileService
{
    /**
     * Cria um perfil de horário padrão e respetivos turnos e dias da semana.
     * Se for um horário futuro (D > hoje), fecha atomicamente o horário em vigor anterior com valid_until = D - 1 dia.
     */
    public function create(array $data): AssistantScheduleProfile
    {
        return DB::transaction(function () use ($data) {
            $assistantId = $data['assistant_id'];
            $newValidFrom = Carbon::parse($data['valid_from'])->startOfDay();

            // Ao criar um novo horário com início a D futuro, fecha a vigência do horário anterior em vigor com D - 1 dia
            if ($newValidFrom->isAfter(Carbon::today())) {
                $previousProfile = AssistantScheduleProfile::where('assistant_id', $assistantId)
                    ->where('valid_from', '<', $newValidFrom)
                    ->where(function ($q) use ($newValidFrom) {
                        $q->whereNull('valid_until')
                            ->orWhere('valid_until', '>=', $newValidFrom);
                    })
                    ->orderByDesc('valid_from')
                    ->first();

                if ($previousProfile) {
                    $previousProfile->update([
                        'valid_until' => $newValidFrom->copy()->subDay()->format('Y-m-d'),
                    ]);
                }
            }

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
                    'lunch_enabled' => isset($shiftData['lunch_enabled']) ? (bool) $shiftData['lunch_enabled'] : false,
                    'lunch_start' => $shiftData['lunch_start'] ?? null,
                    'lunch_end' => $shiftData['lunch_end'] ?? null,
                    'lunch_duration_minutes' => isset($shiftData['lunch_duration_minutes']) && $shiftData['lunch_duration_minutes'] !== null ? (int) $shiftData['lunch_duration_minutes'] : null,
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
     * Atualiza um perfil de horário padrão agendado (futuro).
     * Horários em vigor ou passados não podem ser editados.
     * Se alterar a data de início de D1 para D2, ajusta o término do horário anterior para D2 - 1 dia.
     */
    public function update(AssistantScheduleProfile $profile, array $data): AssistantScheduleProfile
    {
        $today = Carbon::today();
        $oldValidFrom = $profile->valid_from ? Carbon::parse($profile->valid_from)->startOfDay() : null;

        // Horários em vigor ou passados não podem ser editados
        if (! $oldValidFrom || ! $oldValidFrom->isAfter($today)) {
            throw ValidationException::withMessages([
                'profile' => 'Não é possível editar um horário em vigor ou que já regeu escalas passadas.',
            ]);
        }

        return DB::transaction(function () use ($profile, $data, $oldValidFrom) {
            $newValidFrom = isset($data['valid_from']) ? Carbon::parse($data['valid_from'])->startOfDay() : $oldValidFrom;

            // Se alterou o início do novo horário de D1 para D2, ajusta o horário anterior para D2 - 1 dia
            if (! $newValidFrom->equalTo($oldValidFrom)) {
                $previousProfile = AssistantScheduleProfile::where('assistant_id', $profile->assistant_id)
                    ->where('id', '!=', $profile->id)
                    ->where('valid_from', '<', $newValidFrom)
                    ->orderByDesc('valid_from')
                    ->first();

                if ($previousProfile) {
                    $previousProfile->update([
                        'valid_until' => $newValidFrom->copy()->subDay()->format('Y-m-d'),
                    ]);
                }
            }

            $profile->update([
                'type' => $data['type'] ?? $profile->type,
                'rotation_period' => $data['rotation_period'] ?? $profile->rotation_period,
                'starts_with' => $data['starts_with'] ?? $profile->starts_with,
                'valid_from' => $data['valid_from'] ?? $profile->valid_from,
                'valid_until' => array_key_exists('valid_until', $data) ? $data['valid_until'] : $profile->valid_until,
            ]);

            if (isset($data['shifts'])) {
                foreach ($profile->shifts as $existingShift) {
                    $existingShift->shiftDays()->delete();
                }
                $profile->shifts()->delete();

                foreach ($data['shifts'] as $shiftData) {
                    $shift = $profile->shifts()->create([
                        'shift_label' => $shiftData['shift_label'],
                        'entry_time' => $shiftData['entry_time'],
                        'exit_time' => $shiftData['exit_time'],
                        'lunch_enabled' => isset($shiftData['lunch_enabled']) ? (bool) $shiftData['lunch_enabled'] : false,
                        'lunch_start' => $shiftData['lunch_start'] ?? null,
                        'lunch_end' => $shiftData['lunch_end'] ?? null,
                        'lunch_duration_minutes' => isset($shiftData['lunch_duration_minutes']) && $shiftData['lunch_duration_minutes'] !== null ? (int) $shiftData['lunch_duration_minutes'] : null,
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
     * Remove um perfil de horário futuro.
     * Horários em vigor ou passados não podem ser eliminados.
     * Ao cancelar o horário agendado, o horário anterior em vigor volta a ter vigência aberta (valid_until = null).
     */
    public function delete(AssistantScheduleProfile $profile): void
    {
        $today = Carbon::today();
        $validFrom = $profile->valid_from ? Carbon::parse($profile->valid_from)->startOfDay() : null;

        if (! $validFrom || ! $validFrom->isAfter($today)) {
            throw ValidationException::withMessages([
                'profile' => 'Não é possível eliminar um horário em vigor ou que já regeu escalas passadas.',
            ]);
        }

        DB::transaction(function () use ($profile, $validFrom) {
            // Se o admin cancelar o horário agendado, o horário anterior volta a ficar com vigência em aberto (valid_until = null)
            $previousProfile = AssistantScheduleProfile::where('assistant_id', $profile->assistant_id)
                ->where('id', '!=', $profile->id)
                ->where('valid_from', '<', $validFrom)
                ->orderByDesc('valid_from')
                ->first();

            if ($previousProfile) {
                $previousProfile->update(['valid_until' => null]);
            }

            foreach ($profile->shifts as $shift) {
                $shift->shiftDays()->delete();
            }
            $profile->shifts()->delete();
            $profile->delete();
        });
    }
}
