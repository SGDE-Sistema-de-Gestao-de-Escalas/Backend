<?php

namespace App\Services;

use App\Models\Assistants\Assistant;
use App\Models\Assistants\AssistantException;
use App\Models\Schedules\ScheduleEntry;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class AssistantExceptionService
{
    public function __construct(
        private readonly OperatingHoursResolver $operatingHoursResolver
    ) {}

    /**
     * Verifica se a exceção já regeu algum horário em datas passadas (< hoje).
     */
    public function hasPastSchedules(AssistantException $exception): bool
    {
        $todayStr = Carbon::today()->toDateString();
        $validFromStr = $exception->valid_from ? Carbon::parse($exception->valid_from)->toDateString() : null;

        if (! $validFromStr || $validFromStr >= $todayStr) {
            return false;
        }

        $yesterdayStr = Carbon::yesterday()->toDateString();
        $validUntilStr = $exception->valid_until ? Carbon::parse($exception->valid_until)->toDateString() : $yesterdayStr;
        $pastEndStr = min($validUntilStr, $yesterdayStr);

        return ScheduleEntry::query()
            ->whereHas('schedule', fn ($q) => $q->where('assistant_id', $exception->assistant_id)->whereNull('deleted_at'))
            ->whereDate('date', '>=', $validFromStr)
            ->whereDate('date', '<=', $pastEndStr)
            ->exists();
    }

    /**
     * Verifica se existem escalas futuras (>= hoje, em draft ou published) no período da exceção.
     *
     * @return array{has_future_schedules: bool, draft_days_count: int, published_days_count: int, warning: string|null}
     */
    public function checkFutureSchedulesWarning(AssistantException $exception): array
    {
        $todayStr = Carbon::today()->toDateString();
        $validFromStr = $exception->valid_from ? Carbon::parse($exception->valid_from)->toDateString() : $todayStr;
        $validUntilStr = $exception->valid_until ? Carbon::parse($exception->valid_until)->toDateString() : Carbon::today()->addDays(60)->toDateString();

        if ($validUntilStr < $todayStr) {
            return [
                'has_future_schedules' => false,
                'draft_days_count' => 0,
                'published_days_count' => 0,
                'warning' => null,
            ];
        }

        $fromStr = max($validFromStr, $todayStr);

        $entries = ScheduleEntry::query()
            ->with('schedule:id,status,assistant_id')
            ->whereHas('schedule', fn ($q) => $q->where('assistant_id', $exception->assistant_id)->whereNull('deleted_at'))
            ->whereDate('date', '>=', $fromStr)
            ->whereDate('date', '<=', $validUntilStr)
            ->get();

        if ($entries->isEmpty()) {
            return [
                'has_future_schedules' => false,
                'draft_days_count' => 0,
                'published_days_count' => 0,
                'warning' => null,
            ];
        }

        $draftDates = [];
        $publishedDates = [];

        foreach ($entries as $entry) {
            $dateKey = is_string($entry->date) ? substr($entry->date, 0, 10) : $entry->date->format('Y-m-d');
            if ($entry->schedule?->status === 'published') {
                $publishedDates[$dateKey] = true;
            } else {
                $draftDates[$dateKey] = true;
            }
        }

        $draftCount = count($draftDates);
        $publishedCount = count($publishedDates);

        $warning = null;
        if ($draftCount > 0 && $publishedCount > 0) {
            $warning = "Atenção: existem escalas em rascunho ({$draftCount} dia(s)) e publicadas ({$publishedCount} dia(s)) neste período. Deverá recalcular a escala para aplicar a alteração.";
        } elseif ($publishedCount > 0) {
            $warning = "Atenção: existem escalas já publicadas ({$publishedCount} dia(s)) neste período. Deverá recalcular e republicar a escala.";
        } elseif ($draftCount > 0) {
            $warning = "Atenção: existem escalas em rascunho ({$draftCount} dia(s)) neste período que deverão ser recalculadas.";
        }

        return [
            'has_future_schedules' => true,
            'draft_days_count' => $draftCount,
            'published_days_count' => $publishedCount,
            'warning' => $warning,
        ];
    }

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
     * Atualiza uma exceção de assistente permitindo edição se ainda não terminou no passado.
     */
    public function update(AssistantException $exception, array $data): AssistantException
    {
        $status = $this->calculateStatus($exception);

        // Passada (valid_until < hoje): não pode ser editada de todo
        if ($status === 'superseded') {
            throw ValidationException::withMessages([
                'valid_until' => 'Não é possível editar uma exceção cuja vigência já terminou no passado.',
            ]);
        }

        $assistant = $exception->assistant ?? Assistant::findOrFail($exception->assistant_id);
        $mergedData = [
            'valid_from' => $data['valid_from'] ?? $exception->valid_from?->format('Y-m-d'),
            'valid_until' => array_key_exists('valid_until', $data) ? $data['valid_until'] : $exception->valid_until?->format('Y-m-d'),
            'start_time' => array_key_exists('start_time', $data) ? $data['start_time'] : ($exception->start_time ? Carbon::parse($exception->start_time)->format('H:i') : null),
            'end_time' => array_key_exists('end_time', $data) ? $data['end_time'] : ($exception->end_time ? Carbon::parse($exception->end_time)->format('H:i') : null),
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
     * Elimina uma exceção.
     * Só bloqueia a eliminação se a exceção já terminou no passado ou se já tiver regido horários passados (< hoje).
     * Caso tenha horários futuros (draft ou published), permite eliminar e o Controller devolve o aviso de recálculo.
     */
    public function delete(AssistantException $exception): void
    {
        $status = $this->calculateStatus($exception);

        if ($status === 'superseded') {
            throw ValidationException::withMessages([
                'exception' => 'Não é possível eliminar uma exceção cuja vigência já terminou no passado.',
            ]);
        }

        if ($this->hasPastSchedules($exception)) {
            throw ValidationException::withMessages([
                'exception' => 'Não é possível eliminar esta exceção porque já existem escalas passadas geradas sob a sua vigência. Edite a data de fim ou crie uma nova exceção para a dar como terminada.',
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
        $hasValidUntil = ! empty($data['valid_until']);
        $validUntil = $hasValidUntil
            ? Carbon::parse($data['valid_until'])->startOfDay()
            : $validFrom->copy()->addDays(60);

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
