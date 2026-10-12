<?php

namespace App\Services;

use App\Models\Schedules\Holiday;
use App\Models\Schools\SchoolOperatingException;
use App\Models\Schools\SchoolOperatingRule;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Carbon\CarbonPeriod;

/**
 * Decide como funciona a escola em cada data. Ordem de prioridade:
 *
 *  1. Feriado global (a escola fecha).
 *  2. Exceção da escola que cobre a data (fecha ou muda as horas), mas só nos
 *     dias da semana em que o horário fixo funciona.
 *  3. Horário fixo: a versão com a data de início mais recente que já começou,
 *     se tiver horas para esse dia da semana.
 *  4. Nada: a escola não funciona.
 *
 * Carrega os dados do intervalo todo de uma vez (feriados, exceções e versões),
 * por isso o número de consultas não depende do tamanho do intervalo.
 */
class OperatingHoursResolver
{
    public const SOURCE_HOLIDAY = 'holiday';

    public const SOURCE_EXCEPTION = 'exception';

    public const SOURCE_FIXED = 'fixed';

    public const SOURCE_NONE = 'none';

    /**
     * Um dia.
     *
     * @return array<string, mixed>
     */
    public function forDate(string $schoolId, CarbonInterface|string $date): array
    {
        return $this->forRange($schoolId, $date, $date)[0];
    }

    /**
     * Todos os dias de `$from` a `$to` (inclusivos), por ordem.
     *
     * @return list<array<string, mixed>>
     */
    public function forRange(string $schoolId, CarbonInterface|string $from, CarbonInterface|string $to): array
    {
        $from = Carbon::parse($from)->startOfDay();
        $to = Carbon::parse($to)->startOfDay();

        if ($to->lt($from)) {
            return [];
        }

        $fromDate = $from->toDateString();
        $toDate = $to->toDateString();

        $holidays = Holiday::whereBetween('date', [$fromDate, $toDate])
            ->get()
            ->keyBy(fn (Holiday $holiday) => $holiday->date->toDateString());

        $exceptions = SchoolOperatingException::where('school_id', $schoolId)
            ->whereDate('valid_from', '<=', $toDate)
            ->whereDate('valid_until', '>=', $fromDate)
            ->get();

        // Versões que começam até ao fim do intervalo, da mais recente para a
        // mais antiga: a primeira com início <= data é a que vigora nessa data.
        $versions = SchoolOperatingRule::where('school_id', $schoolId)
            ->whereDate('valid_from', '<=', $toDate)
            ->with('schoolOperatingRuleDays')
            ->orderByDesc('valid_from')
            ->get();

        $days = [];

        foreach (CarbonPeriod::create($from, $to) as $day) {
            $date = $day->toDateString();
            $weekday = $day->dayOfWeekIso;

            $holiday = $holidays->get($date);

            if ($holiday !== null) {
                $days[] = $this->closed($date, $weekday, self::SOURCE_HOLIDAY, $holiday->name);

                continue;
            }

            $version = $versions->first(fn (SchoolOperatingRule $rule) => $rule->valid_from->toDateString() <= $date);

            if ($version === null) {
                $days[] = $this->closed($date, $weekday, self::SOURCE_NONE, 'Sem horário fixo em vigor nesta data.');

                continue;
            }

            $fixed = $version->schoolOperatingRuleDays->firstWhere('day_of_week', $weekday);

            if ($fixed === null) {
                $days[] = $this->closed($date, $weekday, self::SOURCE_NONE, 'A escola não funciona neste dia da semana.');

                continue;
            }

            $exception = $exceptions->first(
                fn (SchoolOperatingException $item) => $item->valid_from->toDateString() <= $date
                    && $item->valid_until->toDateString() >= $date
            );

            if ($exception !== null) {
                $days[] = $exception->is_closed
                    ? $this->closed($date, $weekday, self::SOURCE_EXCEPTION, $exception->name)
                    : $this->open($date, $weekday, self::SOURCE_EXCEPTION, $exception->name, $exception);

                continue;
            }

            $days[] = $this->open($date, $weekday, self::SOURCE_FIXED, null, $fixed);
        }

        return $days;
    }

    /**
     * @return array<string, mixed>
     */
    private function closed(string $date, int $weekday, string $source, ?string $reason): array
    {
        return [
            'date' => $date,
            'day_of_week' => $weekday,
            'is_open' => false,
            'source' => $source,
            'reason' => $reason,
            'opening_time' => null,
            'closing_time' => null,
            'lunch_start' => null,
            'lunch_end' => null,
            'lunch_duration_minutes' => null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function open(string $date, int $weekday, string $source, ?string $reason, object $hours): array
    {
        return [
            'date' => $date,
            'day_of_week' => $weekday,
            'is_open' => true,
            'source' => $source,
            'reason' => $reason,
            'opening_time' => substr((string) $hours->opening_time, 0, 5),
            'closing_time' => substr((string) $hours->closing_time, 0, 5),
            'lunch_start' => substr((string) $hours->lunch_start, 0, 5),
            'lunch_end' => substr((string) $hours->lunch_end, 0, 5),
            'lunch_duration_minutes' => (int) $hours->lunch_duration_minutes,
        ];
    }
}
