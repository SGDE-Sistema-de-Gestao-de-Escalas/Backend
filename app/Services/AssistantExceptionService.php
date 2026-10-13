<?php

namespace App\Services;

use App\Models\Assistants\Assistant;
use App\Models\Assistants\AssistantException;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class AssistantExceptionService
{
    public function __construct(
        private readonly OperatingHoursResolver $operatingHoursResolver
    ) {}

    /**
     * Cria uma nova exceção de assistente após validar contra o calendário da escola.
     */
    public function create(array $data): AssistantException
    {
        $assistant = Assistant::findOrFail($data['assistant_id']);
        $this->validateAgainstSchoolCalendar($assistant->school_id, $data);

        return DB::transaction(function () use ($data) {
            return AssistantException::create($data);
        });
    }

    /**
     * Atualiza uma exceção de assistente com regras de ciclo de vida e integridade histórica.
     */
    public function update(AssistantException $exception, array $data): AssistantException
    {
        $status = $this->calculateStatus($exception);

        // 1. Passada: não pode ser editada de todo
        if ($status === 'superseded') {
            throw ValidationException::withMessages([
                'valid_until' => 'Não é possível editar uma exceção cuja vigência já terminou no passado.',
            ]);
        }

        // 2. Em vigor: não pode alterar dados base/início retroativamente; apenas ajustar término
        if ($status === 'current') {
            $immutableFields = ['type', 'valid_from', 'start_time', 'end_time', 'assistant_id', 'days', 'lunch_enabled', 'lunch_start', 'lunch_end', 'lunch_duration_minutes'];
            foreach ($immutableFields as $field) {
                if (isset($data[$field])) {
                    if ($field === 'days') {
                        $existingDays = $exception->days ? array_values(array_map('intval', $exception->days)) : [];
                        $incomingDays = is_array($data['days']) ? array_values(array_map('intval', $data['days'])) : [];
                        sort($existingDays);
                        sort($incomingDays);
                        if ($existingDays !== $incomingDays) {
                            throw ValidationException::withMessages([
                                'days' => 'Não é possível alterar os dias de uma exceção em vigor; apenas a data de fim pode ser ajustada.',
                            ]);
                        }
                        continue;
                    }

                    $existingValue = $exception->{$field};
                    if ($existingValue instanceof Carbon) {
                        $existingValue = $existingValue->format('Y-m-d');
                    }
                    $incomingValue = $data[$field];
                    if ($incomingValue instanceof Carbon) {
                        $incomingValue = $incomingValue->format('Y-m-d');
                    }
                    if ((string) $incomingValue !== (string) $existingValue) {
                        throw ValidationException::withMessages([
                            $field => 'Não é possível alterar os dados base ou data de início de uma exceção em vigor; apenas a data de fim pode ser ajustada.',
                        ]);
                    }
                }
            }

            if (array_key_exists('valid_until', $data) && $data['valid_until'] !== null) {
                $newUntil = Carbon::parse($data['valid_until'])->startOfDay();
                if ($newUntil->isBefore(Carbon::today())) {
                    throw ValidationException::withMessages([
                        'valid_until' => 'A data de fim não pode ser anterior à data de hoje.',
                    ]);
                }
            }
        }

        $assistant = $exception->assistant ?? Assistant::findOrFail($exception->assistant_id);
        $mergedData = [
            'valid_from' => $data['valid_from'] ?? $exception->valid_from?->format('Y-m-d'),
            'valid_until' => array_key_exists('valid_until', $data) ? $data['valid_until'] : $exception->valid_until?->format('Y-m-d'),
            'start_time' => $data['start_time'] ?? ($exception->start_time ? Carbon::parse($exception->start_time)->format('H:i') : null),
            'end_time' => $data['end_time'] ?? ($exception->end_time ? Carbon::parse($exception->end_time)->format('H:i') : null),
            'days' => array_key_exists('days', $data) ? $data['days'] : $exception->days,
            'lunch_enabled' => array_key_exists('lunch_enabled', $data) ? $data['lunch_enabled'] : $exception->lunch_enabled,
            'lunch_start' => array_key_exists('lunch_start', $data) ? $data['lunch_start'] : ($exception->lunch_start ? Carbon::parse($exception->lunch_start)->format('H:i') : null),
            'lunch_end' => array_key_exists('lunch_end', $data) ? $data['lunch_end'] : ($exception->lunch_end ? Carbon::parse($exception->lunch_end)->format('H:i') : null),
            'lunch_duration_minutes' => array_key_exists('lunch_duration_minutes', $data) ? $data['lunch_duration_minutes'] : $exception->lunch_duration_minutes,
            'type' => $data['type'] ?? $exception->type,
        ];
        $this->validateAgainstSchoolCalendar($assistant->school_id, $mergedData);

        return DB::transaction(function () use ($exception, $data) {
            $exception->update($data);

            return $exception->fresh();
        });
    }

    /**
     * Elimina uma exceção se for futura. Exceções em vigor ou passadas não podem ser eliminadas.
     */
    public function delete(AssistantException $exception): void
    {
        $status = $this->calculateStatus($exception);

        if ($status !== 'upcoming') {
            throw ValidationException::withMessages([
                'exception' => 'Não é possível eliminar uma exceção em vigor ou que já regeu escalas passadas.',
            ]);
        }

        $exception->delete();
    }

    /**
     * Valida a exceção contra o calendário de funcionamento efetivo da escola.
     */
    public function validateAgainstSchoolCalendar(string $schoolId, array $data): void
    {
        if (empty($data['valid_from'])) {
            return;
        }

        $validFrom = Carbon::parse($data['valid_from'])->startOfDay();
        $validUntil = ! empty($data['valid_until'])
            ? Carbon::parse($data['valid_until'])->startOfDay()
            : $validFrom;

        // Limita a verificação a um intervalo de no máximo 366 dias para desempenho
        $checkUntil = $validUntil->diffInDays($validFrom) > 366
            ? $validFrom->copy()->addDays(366)
            : $validUntil;

        $calendarDays = $this->operatingHoursResolver->forRange($schoolId, $validFrom, $checkUntil);

        // 1. Filtrar dias de funcionamento previsto no intervalo (e pelos dias da semana se indicados)
        $daysFilter = ! empty($data['days']) && is_array($data['days'])
            ? array_values(array_map('intval', $data['days']))
            : null;

        $openDays = array_values(array_filter($calendarDays, function ($day) use ($daysFilter) {
            if ($day['is_open'] !== true) {
                return false;
            }
            if ($daysFilter !== null && ! in_array($day['day_of_week'], $daysFilter, true)) {
                return false;
            }
            return true;
        }));

        if (empty($openDays)) {
            if ($daysFilter !== null) {
                throw ValidationException::withMessages([
                    'days' => 'A escola não tem funcionamento previsto nos dias da semana selecionados para o intervalo (encerrada, sem horário fixo ou feriado).',
                ]);
            }

            throw ValidationException::withMessages([
                'valid_from' => 'A escola não tem funcionamento previsto no intervalo selecionado (encerrada, sem horário fixo ou feriado).',
            ]);
        }

        // 2. Se a exceção definir horário específico (start_time e/ou end_time)
        $startTime = $data['start_time'] ?? null;
        $endTime = $data['end_time'] ?? null;
        $formattedStart = $startTime ? substr((string) $startTime, 0, 5) : null;
        $formattedEnd = $endTime ? substr((string) $endTime, 0, 5) : null;

        if ($startTime || $endTime) {
            foreach ($openDays as $day) {
                $schoolOpen = $day['opening_time'];
                $schoolClose = $day['closing_time'];

                if ($formattedStart && $schoolOpen && $formattedStart < $schoolOpen) {
                    throw ValidationException::withMessages([
                        'start_time' => "A hora de início ({$formattedStart}) é anterior ao horário de abertura da escola ({$schoolOpen} em {$day['date']}).",
                    ]);
                }

                if ($formattedEnd && $schoolClose && $formattedEnd > $schoolClose) {
                    throw ValidationException::withMessages([
                        'end_time' => "A hora de fim ({$formattedEnd}) é posterior ao horário de fecho da escola ({$schoolClose} em {$day['date']}).",
                    ]);
                }
            }
        }

        // 3. Validação das horas de pausa de almoço dentro da exceção
        $lunchEnabled = ! empty($data['lunch_enabled']);
        $lunchStart = $data['lunch_start'] ?? null;
        $lunchEnd = $data['lunch_end'] ?? null;

        if ($lunchEnabled && ($lunchStart || $lunchEnd)) {
            $formattedLunchStart = $lunchStart ? substr((string) $lunchStart, 0, 5) : null;
            $formattedLunchEnd = $lunchEnd ? substr((string) $lunchEnd, 0, 5) : null;

            if ($formattedStart && $formattedLunchStart && $formattedLunchStart < $formattedStart) {
                throw ValidationException::withMessages([
                    'lunch_start' => "A hora de início de almoço ({$formattedLunchStart}) não pode ser anterior ao início do horário da exceção ({$formattedStart}).",
                ]);
            }

            if ($formattedEnd && $formattedLunchEnd && $formattedLunchEnd > $formattedEnd) {
                throw ValidationException::withMessages([
                    'lunch_end' => "A hora de fim de almoço ({$formattedLunchEnd}) não pode ser posterior ao fim do horário da exceção ({$formattedEnd}).",
                ]);
            }
        }
    }

    /**
     * Determina o estado da exceção: "current" | "upcoming" | "superseded"
     */
    public function calculateStatus(AssistantException $exception): string
    {
        $today = Carbon::today();
        $validFrom = $exception->valid_from ? Carbon::parse($exception->valid_from)->startOfDay() : null;
        $validUntil = $exception->valid_until ? Carbon::parse($exception->valid_until)->startOfDay() : null;

        if ($validFrom && $validFrom->isAfter($today)) {
            return 'upcoming';
        }

        if ($validUntil && $validUntil->isBefore($today)) {
            return 'superseded';
        }

        return 'current';
    }
}
