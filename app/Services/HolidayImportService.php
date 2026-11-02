<?php

namespace App\Services;

use App\Models\Auth\User;
use App\Models\Schedules\Holiday;
use App\Models\Schedules\ScheduleEntry;
use Carbon\CarbonImmutable;
use Spatie\Holidays\Holidays;

class HolidayImportService
{
    public function __construct(
        private readonly ScheduleIntegrityService $scheduleIntegrityService,
        private readonly NotificationService $notificationService
    ) {}

    /**
     * Devolve a lista dos 308 concelhos portugueses.
     *
     * @return array<int, array{slug: string, name: string, district: string, holiday_name: string}>
     */
    public function getMunicipalities(): array
    {
        $all = config('municipalities', []);

        return array_values(array_map(fn ($m) => [
            'slug' => $m['slug'],
            'name' => $m['name'],
            'district' => $m['district'],
            'holiday_name' => $m['holiday_name'],
        ], $all));
    }

    /**
     * Calcula o Domingo de Páscoa usando o algoritmo gregoriano anónimo.
     */
    public function getEasterSunday(int $year): CarbonImmutable
    {
        $a = $year % 19;
        $b = intdiv($year, 100);
        $c = $year % 100;
        $d = intdiv($b, 4);
        $e = $b % 4;
        $f = intdiv($b + 8, 25);
        $g = intdiv($b - $f + 1, 3);
        $h = (19 * $a + $b - $d - $g + 15) % 30;
        $i = intdiv($c, 4);
        $k = $c % 4;
        $l = (32 + 2 * $e + 2 * $i - $h - $k) % 7;
        $m = intdiv($a + 11 * $h + 22 * $l, 451);
        $month = intdiv($h + $l - 7 * $m + 114, 31);
        $day = (($h + $l - 7 * $m + 114) % 31) + 1;

        return CarbonImmutable::createFromDate($year, $month, $day);
    }

    /**
     * Resolve a data de um feriado municipal com base na sua regra.
     */
    public function resolveMunicipalHolidayDate(array $rule, int $year): ?CarbonImmutable
    {
        $type = $rule['type'] ?? 'fixed';

        switch ($type) {
            case 'fixed':
                return CarbonImmutable::createFromDate($year, (int) $rule['month'], (int) $rule['day']);

            case 'easter':
                $easter = $this->getEasterSunday($year);
                return $easter->addDays((int) ($rule['offset'] ?? 0));

            case 'ascension':
                // Quinta-feira da Ascensão = Páscoa + 39 dias
                return $this->getEasterSunday($year)->addDays(39);

            case 'pentecost':
                // Pentecostes / Segunda-feira de Pentecostes = Páscoa + 50 dias
                return $this->getEasterSunday($year)->addDays(50);

            case 'pascoela':
                // Segunda-feira de Pascoela = Páscoa + 8 dias
                return $this->getEasterSunday($year)->addDays(8);

            case 'nearest_mon':
                // Segunda-feira mais próxima ou seguinte ao dia âncora
                $anchor = CarbonImmutable::createFromDate($year, (int) $rule['month'], (int) $rule['day']);
                $dow = $anchor->dayOfWeek; // 0 = Domingo, 1 = Segunda ... 6 = Sábado
                $diff = $dow === 0 ? 1 : ($dow === 1 ? 0 : 8 - $dow);
                return $anchor->addDays($diff);

            default:
                return null;
        }
    }

    /**
     * Gera a pré-visualização de feriados (nacionais + municipal escolhido) para um determinado ano.
     *
     * @return array<int, array{
     *     name: string,
     *     date: string,
     *     type: string,
     *     municipality: ?string,
     *     description: ?string,
     *     already_exists: bool,
     *     is_past: bool
     * }>
     */
    public function preview(int $year, ?string $municipalitySlug = null, bool $includeCarnival = true): array
    {
        $holidaysList = [];
        $today = CarbonImmutable::today()->toDateString();

        // 1. Obter feriados nacionais através de Spatie\Holidays
        $spatieHolidays = Holidays::for('pt', year: $year)->get();
        foreach ($spatieHolidays as $h) {
            $dateStr = $h['date']->format('Y-m-d');
            $holidaysList[$dateStr] = [
                'name' => $h['name'],
                'date' => $dateStr,
                'type' => 'national',
                'municipality' => null,
                'description' => 'Feriado Nacional Obrigatório',
            ];
        }

        // Carnaval (opcional / tolerância de ponto) = Páscoa - 47 dias
        if ($includeCarnival) {
            $carnivalDate = $this->getEasterSunday($year)->subDays(47)->toDateString();
            if (! isset($holidaysList[$carnivalDate])) {
                $holidaysList[$carnivalDate] = [
                    'name' => 'Terça-feira de Carnaval',
                    'date' => $carnivalDate,
                    'type' => 'national',
                    'municipality' => null,
                    'description' => 'Carnaval (Tolerância de ponto)',
                ];
            }
        }

        // 2. Adicionar feriado municipal se município for selecionado
        if ($municipalitySlug) {
            $allMun = config('municipalities', []);
            if (isset($allMun[$municipalitySlug])) {
                $mun = $allMun[$municipalitySlug];
                $munDate = $this->resolveMunicipalHolidayDate($mun['rule'], $year);
                if ($munDate) {
                    $munDateStr = $munDate->format('Y-m-d');
                    $munName = "Feriado Municipal de {$mun['name']}";
                    if (! empty($mun['holiday_name'])) {
                        $munName .= " ({$mun['holiday_name']})";
                    }

                    // Se já coincidir com um feriado nacional, anota na descrição
                    if (isset($holidaysList[$munDateStr])) {
                        $holidaysList[$munDateStr]['name'] .= " / " . $munName;
                        $holidaysList[$munDateStr]['description'] .= " (Coincide com Feriado Municipal de {$mun['name']})";
                    } else {
                        $holidaysList[$munDateStr] = [
                            'name' => $munName,
                            'date' => $munDateStr,
                            'type' => 'municipal',
                            'municipality' => $mun['name'],
                            'description' => "Feriado Municipal do concelho de {$mun['name']} ({$mun['district']})",
                        ];
                    }
                }
            }
        }

        // 3. Verificar existência na base de dados e se é data passada
        $existingDates = Holiday::query()
            ->whereIn('date', array_keys($holidaysList))
            ->pluck('date')
            ->map(fn ($d) => is_string($d) ? substr($d, 0, 10) : $d->format('Y-m-d'))
            ->flip()
            ->all();

        $result = [];
        foreach ($holidaysList as $dateStr => $item) {
            $item['already_exists'] = isset($existingDates[$dateStr]);
            $item['is_past'] = $dateStr < $today;
            $result[] = $item;
        }

        // Ordenar cronologicamente
        usort($result, fn ($a, $b) => strcmp($a['date'], $b['date']));

        return $result;
    }

    /**
     * Importa os feriados selecionados para a base de dados.
     * Avalia o impacto em horários já gerados e emite alertas/notificações de recálculo se necessário.
     *
     * @param  array<string>|null  $selectedDates  Se nulo, importa todos os novos feriados da pré-visualização.
     * @return array<string, mixed>
     */
    public function import(
        int $year,
        ?string $municipalitySlug = null,
        ?array $selectedDates = null,
        bool $includeCarnival = true,
        ?User $actor = null
    ): array {
        $preview = $this->preview($year, $municipalitySlug, $includeCarnival);

        $selectedDatesMap = $selectedDates !== null ? array_flip($selectedDates) : null;

        $createdHolidays = [];
        $skippedCount = 0;

        foreach ($preview as $item) {
            if ($selectedDatesMap !== null && ! isset($selectedDatesMap[$item['date']])) {
                continue;
            }

            if ($item['already_exists']) {
                $skippedCount++;
                continue;
            }

            // Criar feriado se ainda não existir
            $holiday = Holiday::firstOrCreate(
                ['date' => $item['date']],
                [
                    'name' => $item['name'],
                    'description' => $item['description'],
                ]
            );

            if ($holiday->wasRecentlyCreated) {
                $createdHolidays[] = $holiday;
            } else {
                $skippedCount++;
            }
        }

        // Verificar impacto em escolas com escalas geradas nas datas criadas
        $createdDates = array_map(fn ($h) => is_string($h->date) ? substr($h->date, 0, 10) : $h->date->format('Y-m-d'), $createdHolidays);
        $today = CarbonImmutable::today()->toDateString();
        $futureDates = array_filter($createdDates, fn ($d) => $d >= $today);

        $impactedSchools = [];

        if (! empty($futureDates)) {
            $schoolIdsWithSchedules = ScheduleEntry::query()
                ->whereIn('date', $futureDates)
                ->whereHas('schedule')
                ->join('schedules', 'schedule_entries.schedule_id', '=', 'schedules.id')
                ->distinct()
                ->pluck('schedules.school_id')
                ->all();

            $minDate = min($futureDates);
            $maxDate = max($futureDates);

            foreach ($schoolIdsWithSchedules as $schoolId) {
                $impact = $this->scheduleIntegrityService->checkImpact(
                    schoolId: (string) $schoolId,
                    startDate: $minDate,
                    endDate: $maxDate,
                    eventType: 'holiday'
                );

                if (! empty($impact['requires_recalculation'])) {
                    $this->notificationService->scheduleRecalculationRequired(
                        schoolId: (string) $schoolId,
                        impact: $impact,
                        assistantName: null,
                        cause: 'feriado',
                        actor: $actor
                    );

                    $impactedSchools[] = [
                        'school_id' => $schoolId,
                        'impact' => $impact,
                    ];
                }
            }
        }

        return [
            'imported_count' => count($createdHolidays),
            'skipped_count' => $skippedCount,
            'total_processed' => count($createdHolidays) + $skippedCount,
            'created' => $createdHolidays,
            'requires_recalculation' => count($impactedSchools) > 0,
            'impacted_schools' => $impactedSchools,
        ];
    }
}
