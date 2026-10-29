<?php

namespace App\Services;

use App\Models\Schedules\AssistantScheduleProfile;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class AssistantScheduleProfileService
{
    /**
     * Normaliza encadeamento histórico de perfis de um assistente caso existam
     * perfis anteriores que ficaram sem `valid_until` fechado antes da correção.
     */
    public function normalizeAssistantProfilesChain(string $assistantId): void
    {
        $profiles = AssistantScheduleProfile::where('assistant_id', $assistantId)
            ->orderBy('valid_from')
            ->get()
            ->values();

        for ($i = 0; $i < $profiles->count() - 1; $i++) {
            $current = $profiles[$i];
            $next = $profiles[$i + 1];

            if (! $current->valid_from || ! $next->valid_from) {
                continue;
            }

            $nextFromStr = Carbon::parse($next->valid_from)->format('Y-m-d');
            $expectedUntilStr = Carbon::parse($next->valid_from)->subDay()->format('Y-m-d');
            $currentUntilStr = $current->valid_until ? Carbon::parse($current->valid_until)->format('Y-m-d') : null;

            if ($currentUntilStr === null || $currentUntilStr >= $nextFromStr) {
                $current->update(['valid_until' => $expectedUntilStr]);
            }
        }
    }

    /**
     * Cria um perfil de horário padrão e respetivos turnos e dias da semana.
     * Sempre que é criado um novo perfil de horário para o assistente, fecha atomicamente
     * o perfil anterior em vigor (ou sobreposto) com valid_until = D - 1 dia, e ajusta o
     * valid_until do novo perfil caso já exista um perfil futuro posterior.
     */
    public function create(array $data): AssistantScheduleProfile
    {
        return DB::transaction(function () use ($data) {
            $assistantId = $data['assistant_id'];
            $this->normalizeAssistantProfilesChain($assistantId);

            $newValidFrom = Carbon::parse($data['valid_from'])->startOfDay();
            $newValidFromStr = $newValidFrom->format('Y-m-d');

            // Impedir dois perfis de horário para o mesmo assistente com a mesma data de início
            $duplicateStart = AssistantScheduleProfile::where('assistant_id', $assistantId)
                ->whereDate('valid_from', $newValidFromStr)
                ->exists();

            if ($duplicateStart) {
                throw ValidationException::withMessages([
                    'valid_from' => 'Já existe um perfil de horário para este assistente a começar nesta data.',
                ]);
            }

            // 1. Fechar qualquer perfil anterior (valid_from < D) que estivesse aberto ou terminasse em/após D
            $previousEndStr = $newValidFrom->copy()->subDay()->format('Y-m-d');
            AssistantScheduleProfile::where('assistant_id', $assistantId)
                ->whereDate('valid_from', '<', $newValidFromStr)
                ->where(function ($q) use ($newValidFromStr) {
                    $q->whereNull('valid_until')
                        ->orWhereDate('valid_until', '>=', $newValidFromStr);
                })
                ->update([
                    'valid_until' => $previousEndStr,
                ]);

            // 2. Se já existir um perfil futuro posterior (valid_from > D), o novo perfil termina na véspera dele
            $nextProfile = AssistantScheduleProfile::where('assistant_id', $assistantId)
                ->whereDate('valid_from', '>', $newValidFromStr)
                ->orderBy('valid_from')
                ->first();

            $computedValidUntil = $data['valid_until'] ?? null;
            if ($nextProfile && $nextProfile->valid_from) {
                $maxValidUntil = Carbon::parse($nextProfile->valid_from)->subDay()->format('Y-m-d');
                if ($computedValidUntil === null || $computedValidUntil > $maxValidUntil) {
                    $computedValidUntil = $maxValidUntil;
                }
            }

            $profile = AssistantScheduleProfile::create([
                'assistant_id' => $data['assistant_id'],
                'type' => $data['type'],
                'rotation_period' => $data['rotation_period'] ?? null,
                'starts_with' => $data['starts_with'] ?? null,
                'valid_from' => $newValidFromStr,
                'valid_until' => $computedValidUntil,
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
            $newValidFromStr = $newValidFrom->format('Y-m-d');

            if (! $newValidFrom->equalTo($oldValidFrom)) {
                $duplicateStart = AssistantScheduleProfile::where('assistant_id', $profile->assistant_id)
                    ->where('id', '!=', $profile->id)
                    ->whereDate('valid_from', $newValidFromStr)
                    ->exists();

                if ($duplicateStart) {
                    throw ValidationException::withMessages([
                        'valid_from' => 'Já existe um perfil de horário para este assistente a começar nesta data.',
                    ]);
                }

                // Se alterou o início do novo horário de D1 para D2, ajusta o horário anterior para D2 - 1 dia
                $previousProfile = AssistantScheduleProfile::where('assistant_id', $profile->assistant_id)
                    ->where('id', '!=', $profile->id)
                    ->whereDate('valid_from', '<', $newValidFromStr)
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
            $validFromStr = $validFrom->format('Y-m-d');

            // Se o admin cancelar o horário agendado, o horário anterior ajusta o valid_until para a véspera do seguinte (ou null se não houver seguinte)
            $previousProfile = AssistantScheduleProfile::where('assistant_id', $profile->assistant_id)
                ->where('id', '!=', $profile->id)
                ->whereDate('valid_from', '<', $validFromStr)
                ->orderByDesc('valid_from')
                ->first();

            if ($previousProfile) {
                $nextProfile = AssistantScheduleProfile::where('assistant_id', $profile->assistant_id)
                    ->where('id', '!=', $profile->id)
                    ->whereDate('valid_from', '>', $validFromStr)
                    ->orderBy('valid_from')
                    ->first();

                $restoredValidUntil = $nextProfile && $nextProfile->valid_from
                    ? Carbon::parse($nextProfile->valid_from)->subDay()->format('Y-m-d')
                    : null;

                $previousProfile->update(['valid_until' => $restoredValidUntil]);
            }

            foreach ($profile->shifts as $shift) {
                $shift->shiftDays()->delete();
            }
            $profile->shifts()->delete();
            $profile->delete();
        });
    }
}
