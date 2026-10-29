<?php

namespace App\Services;

use App\Models\Absences\Absence;
use App\Models\Assistants\Assistant;
use App\Models\Schedules\ScheduleEntry;
use App\Models\Schools\School;
use App\Models\System\ActivityType;
use Barryvdh\DomPDF\Facade\Pdf;
use Barryvdh\DomPDF\PDF as DomPdfInstance;
use Carbon\Carbon;

class ScheduleExportService
{
    private const DAY_NAMES = [
        0 => 'Domingo',
        1 => 'Segunda-feira',
        2 => 'Terça-feira',
        3 => 'Quarta-feira',
        4 => 'Quinta-feira',
        5 => 'Sexta-feira',
        6 => 'Sábado',
    ];

    private const MONTH_NAMES = [
        1 => 'Janeiro',
        2 => 'Fevereiro',
        3 => 'Março',
        4 => 'Abril',
        5 => 'Maio',
        6 => 'Junho',
        7 => 'Julho',
        8 => 'Agosto',
        9 => 'Setembro',
        10 => 'Outubro',
        11 => 'Novembro',
        12 => 'Dezembro',
    ];

    private const SHORT_MONTH_NAMES = [
        1 => 'Jan',
        2 => 'Fev',
        3 => 'Mar',
        4 => 'Abr',
        5 => 'Mai',
        6 => 'Jun',
        7 => 'Jul',
        8 => 'Ago',
        9 => 'Set',
        10 => 'Out',
        11 => 'Nov',
        12 => 'Dez',
    ];

    public function __construct(
        private readonly OperatingHoursResolver $operatingHoursResolver
    ) {}

    /**
     * Calcula os limites completos da grelha de calendário mensal (Segunda-feira da 1ª semana
     * até Domingo da última semana do mês de referência).
     *
     * @return array{grid_start: string, grid_end: string, month: int, year: int, month_year_title: string}
     */
    /**
     * Valida se existem escalas publicadas para permitir a impressão/exportação.
     * Devolve null se for válido ou a mensagem de erro (PT-PT) caso não possa exportar.
     */
    public function validateExportableSchedules(
        School|string $school,
        string $startDate,
        string $endDate,
        string $model = 'wall',
        ?string $assistantFilterId = null
    ): ?string {
        $schoolModel = $school instanceof School ? $school : School::findOrFail($school);

        $operatingDays = $this->operatingHoursResolver->forRange($schoolModel->id, $startDate, $endDate);

        $openDays = array_values(array_filter(
            $operatingDays,
            fn (array $od) => (bool) ($od['is_open'] ?? false)
        ));

        $publishedDates = ScheduleEntry::whereBetween('date', [$startDate, $endDate])
            ->whereHas('schedule', fn ($q) => $q->where('school_id', $schoolModel->id)->where('status', 'published'))
            ->pluck('date')
            ->map(fn ($d) => is_string($d) ? substr($d, 0, 10) : $d->format('Y-m-d'))
            ->unique()
            ->values();

        $startFmt = Carbon::parse($startDate)->format('d/m/Y');
        $endFmt = Carbon::parse($endDate)->format('d/m/Y');

        if ($model === 'wall') {
            if (empty($openDays)) {
                return $startDate === $endDate
                    ? "O estabelecimento encontra-se encerrado no dia {$startFmt}. Não existe escala diária para imprimir."
                    : "O estabelecimento encontra-se encerrado no período de {$startFmt} a {$endFmt}.";
            }

            $missingDates = [];
            foreach ($openDays as $od) {
                $dStr = (string) $od['date'];
                if (! $publishedDates->contains($dStr)) {
                    $missingDates[] = Carbon::parse($dStr)->format('d/m/Y');
                }
            }

            if (! empty($missingDates)) {
                if ($startDate === $endDate || count($openDays) === 1) {
                    return "Não existe nenhuma escala publicada para o dia {$missingDates[0]}. Publique a escala antes de imprimir ou exportar.";
                }

                if (count($missingDates) === count($openDays)) {
                    return "Não existe nenhuma escala publicada no intervalo de {$startFmt} a {$endFmt}. Publique a escala antes de imprimir ou exportar.";
                }

                return 'Não existe escala publicada para o(s) dia(s) ' . implode(', ', $missingDates) . '. Publique as escalas desses dias antes de imprimir a Escala Diária.';
            }

            return null;
        }

        // Modelo 'individual' (Horário Individual por Assistente)
        if ($publishedDates->isEmpty()) {
            return $startDate === $endDate
                ? "Não existe nenhuma escala publicada para o dia {$startFmt}. Publique a escala antes de imprimir ou exportar."
                : "Não existe nenhuma escala publicada no intervalo de {$startFmt} a {$endFmt}. Publique a escala antes de imprimir ou exportar.";
        }

        if ($assistantFilterId !== null && $assistantFilterId !== '' && $assistantFilterId !== 'all') {
            $hasAssistantPublished = ScheduleEntry::whereBetween('date', [$startDate, $endDate])
                ->whereHas('schedule', fn ($q) => $q
                    ->where('school_id', $schoolModel->id)
                    ->where('status', 'published')
                    ->where('assistant_id', $assistantFilterId)
                )
                ->exists();

            if (! $hasAssistantPublished) {
                return $startDate === $endDate
                    ? "Não existe nenhuma escala publicada para o assistente selecionado no dia {$startFmt}. Publique a escala antes de imprimir ou exportar."
                    : "Não existe nenhuma escala publicada para o assistente selecionado no intervalo de {$startFmt} a {$endFmt}. Publique a escala antes de imprimir ou exportar.";
            }
        }

        return null;
    }

    /**
     * Gera a instância PDF (A4 Landscape) para a escala no intervalo de datas.
     */
    public function generatePdf(
        School|string $school,
        string $startDate,
        string $endDate,
        string $model = 'wall',
        ?string $assistantFilterId = null
    ): DomPdfInstance {
        $schoolModel = $school instanceof School ? $school : School::findOrFail($school);

        $logoPath = resource_path('views/exports/logo-horizontal.svg');
        $logoBase64 = is_file($logoPath)
            ? 'data:image/svg+xml;base64,' . base64_encode((string) file_get_contents($logoPath))
            : null;

        $printedAt = Carbon::now()->format('d/m/Y H:i');
        $appVersion = 'SGDE v1.0';

        if ($model === 'individual') {
            $individualData = $this->buildIndividualExportData(
                $schoolModel,
                $startDate,
                $endDate,
                $assistantFilterId
            );

            return Pdf::loadView('exports.schedules-individual', array_merge($individualData, [
                'school' => $schoolModel,
                'logoBase64' => $logoBase64,
                'printedAt' => $printedAt,
                'appVersion' => $appVersion,
            ]))->setPaper('a4', 'landscape');
        }

        $days = $this->buildDaysData($schoolModel, $startDate, $endDate, $assistantFilterId);

        return Pdf::loadView('exports.schedules-wall', [
            'school' => $schoolModel,
            'days' => $days,
            'docTitle' => 'Escala Diária de Serviço',
            'logoBase64' => $logoBase64,
            'printedAt' => $printedAt,
            'appVersion' => $appVersion,
        ])->setPaper('a4', 'landscape');
    }

    /**
     * Escreve os dados da escala em formato CSV (UTF-8 com BOM, separador ";") num stream.
     * Por motivos de privacidade (RGPD), nunca expõe o motivo de ausência.
     *
     * @param resource $stream
     */
    public function writeCsv(
        $stream,
        School|string $school,
        string $startDate,
        string $endDate,
        ?string $assistantFilterId = null,
        string $model = 'wall'
    ): void {
        $schoolModel = $school instanceof School ? $school : School::findOrFail($school);

        fwrite($stream, "\xEF\xBB\xBF");

        fputcsv($stream, [
            'Escola',
            'Data',
            'Dia da Semana',
            'Nº Mecanográfico',
            'Assistente',
            'Turno',
            'Almoço',
            'Atividades Atribuídas',
            'Estado',
        ], ';');

        $days = $this->buildDaysData($schoolModel, $startDate, $endDate, $assistantFilterId);

        foreach ($days as $day) {
            if (! $day['is_open']) {
                fputcsv($stream, [
                    $schoolModel->name,
                    $day['date'],
                    $day['day_name'],
                    '--',
                    '--',
                    'Folga Semanal',
                    '--',
                    $day['closed_reason'] ?: 'Folga / Estabelecimento Encerrado',
                    'Folga / Encerrado',
                ], ';');

                continue;
            }

            foreach ($day['rows'] as $row) {
                if ($row['is_absent']) {
                    fputcsv($stream, [
                        $schoolModel->name,
                        $day['date'],
                        $day['day_name'],
                        $row['code'] ?: '--',
                        $row['name'],
                        $row['shift'],
                        '--',
                        '--',
                        'Ausente',
                    ], ';');

                    continue;
                }

                $tasksSummary = implode(' | ', array_map(
                    fn (array $b) => "{$b['start']}-{$b['end']}: {$b['full_activity']}",
                    $row['work_blocks']
                ));

                fputcsv($stream, [
                    $schoolModel->name,
                    $day['date'],
                    $day['day_name'],
                    $row['code'] ?: '--',
                    $row['name'],
                    $row['shift'],
                    $row['lunch'] ?: 'Sem pausa almoço',
                    $tasksSummary ?: 'Apoio Geral',
                    'Em Serviço',
                ], ';');
            }
        }
    }

    /**
     * Constrói a estrutura de dados para o Horário Individual de Serviço (1 folha por assistente,
     * com todos os dias do período — incluindo dias de folga/encerramento e dias sem horário publicado — e altura dinâmica).
     * Apenas considera escalas com status = 'published'.
     *
     * @return array<string, mixed>
     */
    public function buildIndividualExportData(
        School $school,
        string $startDate,
        string $endDate,
        ?string $assistantFilterId = null
    ): array {
        $operatingDays = $this->operatingHoursResolver->forRange($school->id, $startDate, $endDate);

        $openTime = '07:30';
        $closeTime = '18:00';
        foreach ($operatingDays as $od) {
            if (! empty($od['opening_time'])) {
                $openTime = $od['opening_time'];
                break;
            }
        }
        foreach ($operatingDays as $od) {
            if (! empty($od['closing_time'])) {
                $closeTime = $od['closing_time'];
                break;
            }
        }

        $winStartMin = $this->timeToMin($openTime);
        $winEndMin = $this->timeToMin($closeTime);
        $winTotalMin = max(60, $winEndMin - $winStartMin);

        $timeHeaders = [];
        for ($m = $winStartMin; $m < $winEndMin; $m += 30) {
            $hh = str_pad((string) floor($m / 60), 2, '0', STR_PAD_LEFT);
            $mm = str_pad((string) ($m % 60), 2, '0', STR_PAD_LEFT);
            $timeHeaders[] = [
                'label' => "$hh:$mm",
                'left' => round((($m - $winStartMin) / $winTotalMin) * 100, 3),
                'width' => round((30 / $winTotalMin) * 100, 3),
            ];
        }

        $quarterTicks = [];
        for ($m = $winStartMin; $m <= $winEndMin; $m += 15) {
            $quarterTicks[] = [
                'left' => round((($m - $winStartMin) / $winTotalMin) * 100, 3),
                'is_hour' => ($m % 60 === 0),
                'is_half' => ($m % 30 === 0),
            ];
        }

        $schoolActivityTypes = ActivityType::where('school_id', $school->id)
            ->where('active', true)
            ->get();

        $activityColorMap = [];
        foreach ($schoolActivityTypes as $at) {
            $activityColorMap[mb_strtolower(trim($at->name))] = $at->color ?: '#6366F1';
        }

        // Apenas entradas de escalas PUBLICADAS (status = 'published')
        $entries = ScheduleEntry::whereBetween('date', [$startDate, $endDate])
            ->whereHas('schedule', fn ($q) => $q->where('school_id', $school->id)->where('status', 'published'))
            ->with(['schedule.assistant.user', 'activityType'])
            ->orderBy('start_time')
            ->get();

        $publishedDatesInSchool = $entries
            ->map(fn (ScheduleEntry $e) => is_string($e->date) ? substr($e->date, 0, 10) : $e->date->format('Y-m-d'))
            ->unique()
            ->values();

        $schoolAssistants = Assistant::where('school_id', $school->id)
            ->with(['user', 'assistantScheduleProfiles.shifts.shiftDays'])
            ->orderBy('internal_number')
            ->orderBy('id')
            ->get()
            ->keyBy(fn (Assistant $a) => (string) $a->id);

        foreach ($entries as $entry) {
            $assistant = $entry->schedule?->assistant;
            if ($assistant && ! $schoolAssistants->has((string) $assistant->id)) {
                $assistant->loadMissing(['user', 'assistantScheduleProfiles.shifts.shiftDays']);
                $schoolAssistants->put((string) $assistant->id, $assistant);
            }
        }

        if ($assistantFilterId !== null && $assistantFilterId !== '' && $assistantFilterId !== 'all') {
            if (! $schoolAssistants->has((string) $assistantFilterId)) {
                $extraAssistant = Assistant::with(['user', 'assistantScheduleProfiles.shifts.shiftDays'])
                    ->find($assistantFilterId);
                if ($extraAssistant) {
                    $schoolAssistants->put((string) $extraAssistant->id, $extraAssistant);
                }
            }

            // Importante: nunca usar Eloquent\Collection::only() pois reindexa as chaves para 0
            $schoolAssistants = $schoolAssistants
                ->filter(fn (Assistant $a) => (string) $a->id === (string) $assistantFilterId)
                ->keyBy(fn (Assistant $a) => (string) $a->id);
        }

        $assistantIds = $schoolAssistants->map(fn (Assistant $a) => (string) $a->id)->values()->all();

        $absences = Absence::whereDate('valid_from', '<=', $endDate)
            ->whereDate('valid_until', '>=', $startDate)
            ->whereIn('assistant_id', $assistantIds)
            ->get()
            ->groupBy(fn (Absence $ab) => (string) $ab->assistant_id);

        $entriesByAssistant = $entries->groupBy(fn (ScheduleEntry $e) => (string) $e->schedule->assistant_id);

        $sheets = [];

        foreach ($schoolAssistants as $assistant) {
            if (! $assistant || ! $assistant->user) {
                continue;
            }

            $assistantId = (string) $assistant->id;
            $aEntriesAll = $entriesByAssistant->get($assistantId, collect());
            $aAbsences = $absences->get($assistantId, collect());

            if (
                ($assistantFilterId === null || $assistantFilterId === '' || $assistantFilterId === 'all')
                && $aEntriesAll->isEmpty()
                && $aAbsences->isEmpty()
            ) {
                continue;
            }

            $entriesByDate = $aEntriesAll->groupBy(
                fn (ScheduleEntry $e) => is_string($e->date) ? substr($e->date, 0, 10) : $e->date->format('Y-m-d')
            );

            $legendMap = [];
            $dayRows = [];
            $hasUnpublished = false;

            foreach ($operatingDays as $od) {
                $dStr = (string) $od['date'];
                $cDate = Carbon::parse($dStr);
                $dName = self::DAY_NAMES[$cDate->dayOfWeek] ?? '';
                $shortDate = $cDate->format('d/m/Y');

                if (! $od['is_open']) {
                    $isHoliday = ($od['source'] ?? '') === 'holiday';
                    $dayOffLabel = $isHoliday
                        ? 'FERIADO — ' . mb_strtoupper((string) ($od['reason'] ?: 'ENCERRADO'))
                        : 'FOLGA / DESCANSO SEMANAL';
                    $shiftLabel = $isHoliday ? 'Feriado' : 'Folga Semanal';
                    $subLabel = $isHoliday ? ($od['reason'] ?: 'Estabelecimento encerrado') : 'Estabelecimento encerrado';

                    $dayRows[] = [
                        'day_name' => $dName,
                        'date_formatted' => $shortDate,
                        'shift' => $shiftLabel,
                        'lunch' => $subLabel,
                        'is_absent' => false,
                        'is_day_off' => true,
                        'is_unpublished' => false,
                        'timeline_blocks' => [
                            [
                                'left' => 0.2,
                                'width' => 99.6,
                                'label' => $dayOffLabel,
                                'type' => 'day_off',
                            ],
                        ],
                    ];

                    continue;
                }

                $absenceRecord = $aAbsences->first(function (Absence $ab) use ($dStr) {
                    $f = $ab->valid_from ? $ab->valid_from->format('Y-m-d') : '';
                    $u = $ab->valid_until ? $ab->valid_until->format('Y-m-d') : $f;

                    return $f <= $dStr && $u >= $dStr;
                });

                $isFullDayAbsent = $absenceRecord !== null && (empty($absenceRecord->start_time) || empty($absenceRecord->end_time));
                $dEntries = $entriesByDate->get($dStr, collect())->sortBy('start_time')->values();
                $dayIsPublishedForSchool = $publishedDatesInSchool->contains($dStr);

                if ($dayIsPublishedForSchool && ($isFullDayAbsent || ($absenceRecord !== null && $dEntries->isEmpty()))) {
                    [$shiftStart, $shiftEnd] = $this->resolveDefaultShift($assistant, $dStr, (int) $od['day_of_week']);
                    $sMin = max($winStartMin, $this->timeToMin($shiftStart));
                    $eMin = min($winEndMin, $this->timeToMin($shiftEnd));
                    if ($eMin <= $sMin) {
                        $sMin = max($winStartMin, $this->timeToMin('08:30'));
                        $eMin = min($winEndMin, $this->timeToMin('16:30'));
                    }

                    $dayRows[] = [
                        'day_name' => $dName,
                        'date_formatted' => $shortDate,
                        'shift' => "$shiftStart – $shiftEnd",
                        'lunch' => 'Ausente',
                        'is_absent' => true,
                        'is_day_off' => false,
                        'is_unpublished' => false,
                        'timeline_blocks' => [
                            [
                                'left' => round((($sMin - $winStartMin) / $winTotalMin) * 100, 3),
                                'width' => round((max(30, $eMin - $sMin) / $winTotalMin) * 100, 3),
                                'label' => 'AUSENTE',
                                'type' => 'absent',
                            ],
                        ],
                    ];

                    continue;
                }

                if ($dEntries->isEmpty()) {
                    // Verificar se o assistente pertence à escola e tem turno contratual para este dia útil
                    $contractShift = ((string) $assistant->school_id === (string) $school->id)
                        ? $this->resolveShiftForDay($assistant, $dStr, (int) $od['day_of_week'])
                        : null;

                    if ($contractShift !== null) {
                        [$shiftStart, $shiftEnd] = $contractShift;
                        $hasUnpublished = true;

                        $dayRows[] = [
                            'day_name' => $dName,
                            'date_formatted' => $shortDate,
                            'shift' => "$shiftStart – $shiftEnd",
                            'lunch' => 'Sem horário publicado',
                            'is_absent' => false,
                            'is_day_off' => false,
                            'is_unpublished' => true,
                            'timeline_blocks' => [
                                [
                                    'left' => 0.2,
                                    'width' => 99.6,
                                    'label' => 'SEM HORÁRIO PUBLICADO PARA ESTE DIA',
                                    'type' => 'unpublished',
                                ],
                            ],
                        ];

                        continue;
                    }

                    $dayRows[] = [
                        'day_name' => $dName,
                        'date_formatted' => $shortDate,
                        'shift' => 'Folga Semanal',
                        'lunch' => 'Descanso semanal',
                        'is_absent' => false,
                        'is_day_off' => true,
                        'is_unpublished' => false,
                        'timeline_blocks' => [
                            [
                                'left' => 0.2,
                                'width' => 99.6,
                                'label' => 'FOLGA / DESCANSO SEMANAL',
                                'type' => 'day_off',
                            ],
                        ],
                    ];

                    continue;
                }

                $merged = [];
                foreach ($dEntries as $entry) {
                    $st = substr((string) $entry->start_time, 0, 5);
                    $et = substr((string) $entry->end_time, 0, 5);
                    $actName = (string) ($entry->activity_name ?: ($entry->activityType?->name ?? 'Atividade'));
                    $color = $entry->activityType?->color
                        ?? $activityColorMap[mb_strtolower(trim($actName))]
                        ?? '#6366F1';

                    $last = count($merged) - 1;
                    if (
                        $last >= 0
                        && $merged[$last]['end'] === $st
                        && mb_strtolower($merged[$last]['activity']) === mb_strtolower($actName)
                    ) {
                        $merged[$last]['end'] = $et;
                    } else {
                        $merged[] = [
                            'start' => $st,
                            'end' => $et,
                            'activity' => $actName,
                            'color' => $color,
                        ];
                    }
                }

                $shiftStart = $merged[0]['start'];
                $shiftEnd = $merged[count($merged) - 1]['end'];
                $lunchStr = null;
                $tlBlocks = [];
                $prevEnd = null;

                foreach ($merged as $m) {
                    $st = $m['start'];
                    $et = $m['end'];
                    $actName = $m['activity'];
                    $color = $m['color'];

                    if ($prevEnd !== null && $this->timeToMin($st) > $this->timeToMin($prevEnd)) {
                        $gapMin = $this->timeToMin($st) - $this->timeToMin($prevEnd);
                        if ($gapMin >= 30) {
                            $lunchStr = "$prevEnd – $st";
                            $tlBlocks[] = [
                                'left' => round((($this->timeToMin($prevEnd) - $winStartMin) / $winTotalMin) * 100, 3),
                                'width' => round(($gapMin / $winTotalMin) * 100, 3),
                                'start' => $prevEnd,
                                'end' => $st,
                                'dur' => $gapMin,
                                'label' => 'Almoço',
                                'type' => 'lunch',
                            ];
                        }
                    }

                    $durMin = max(15, $this->timeToMin($et) - $this->timeToMin($st));

                    if (! isset($legendMap[$actName])) {
                        $legendMap[$actName] = [
                            'name' => $actName,
                            'color' => $color,
                            'bg' => $this->tintHex($color, 0.85),
                            'border' => $this->tintHex($color, 0.55),
                        ];
                    }

                    $tlBlocks[] = [
                        'left' => max(0, round((($this->timeToMin($st) - $winStartMin) / $winTotalMin) * 100, 3)),
                        'width' => round(($durMin / $winTotalMin) * 100, 3),
                        'start' => $st,
                        'end' => $et,
                        'dur' => $durMin,
                        'label' => $this->shortActivityLabel($actName, $durMin),
                        'type' => 'work',
                        'color' => $color,
                        'bg' => $this->tintHex($color, 0.85),
                        'border' => $this->tintHex($color, 0.55),
                        'text' => $this->darkenHex($color, 0.45),
                    ];

                    $prevEnd = $et;
                }

                $dayRows[] = [
                    'day_name' => $dName,
                    'date_formatted' => $shortDate,
                    'shift' => "$shiftStart – $shiftEnd",
                    'lunch' => $lunchStr ? "Almoço: $lunchStr" : 'Sem pausa almoço',
                    'is_absent' => false,
                    'is_day_off' => false,
                    'is_unpublished' => false,
                    'timeline_blocks' => $tlBlocks,
                ];
            }

            $sheets[] = [
                'assistant_name' => trim(($assistant->user->first_name ?? '') . ' ' . ($assistant->user->last_name ?? '')),
                'assistant_code' => $assistant->internal_number ?? '',
                'legend_activities' => array_values($legendMap),
                'has_unpublished' => $hasUnpublished,
                'day_rows' => $dayRows,
            ];
        }

        $rowsCount = max(1, count($operatingDays));
        $rowHeightPt = (int) min(76, max(54, floor(420 / $rowsCount)));
        $blockTopPt = 5;
        $blockHeightPt = round($rowHeightPt - 13.2, 1);
        $innerPadTopNormal = round(max(6, ($blockHeightPt - 18) / 2), 1);
        $innerPadTop15 = round(max(4, ($blockHeightPt - 22) / 2), 1);
        $innerPadTopCenter = round(max(8, ($blockHeightPt - 9) / 2), 1);

        $sCarbon = Carbon::parse($startDate);
        $eCarbon = Carbon::parse($endDate);
        if ($startDate === $endDate) {
            $periodLabel = sprintf(
                '%s, %s de %s de %s',
                self::DAY_NAMES[$sCarbon->dayOfWeek] ?? '',
                $sCarbon->format('d'),
                self::MONTH_NAMES[(int) $sCarbon->format('m')] ?? '',
                $sCarbon->format('Y')
            );
        } else {
            $periodLabel = sprintf(
                '%s de %s a %s de %s de %s',
                $sCarbon->format('d'),
                self::MONTH_NAMES[(int) $sCarbon->format('m')] ?? '',
                $eCarbon->format('d'),
                self::MONTH_NAMES[(int) $eCarbon->format('m')] ?? '',
                $eCarbon->format('Y')
            );
        }

        return [
            'sheets' => $sheets,
            'periodLabel' => $periodLabel,
            'openTime' => $openTime,
            'closeTime' => $closeTime,
            'timeHeaders' => $timeHeaders,
            'quarterTicks' => $quarterTicks,
            'rowHeightPt' => $rowHeightPt,
            'blockTopPt' => $blockTopPt,
            'blockHeightPt' => $blockHeightPt,
            'innerPadTopNormal' => $innerPadTopNormal,
            'innerPadTop15' => $innerPadTop15,
            'innerPadTopCenter' => $innerPadTopCenter,
        ];
    }

    /**
     * Constrói a estrutura de dados diária para exportação PDF (Escala Diária) e CSV.
     * Apenas considera escalas com status = 'published'.
     *
     * @return list<array<string, mixed>>
     */
    public function buildDaysData(
        School $school,
        string $startDate,
        string $endDate,
        ?string $assistantFilterId = null
    ): array {
        $operatingDays = $this->operatingHoursResolver->forRange($school->id, $startDate, $endDate);

        $schoolActivityTypes = ActivityType::where('school_id', $school->id)
            ->where('active', true)
            ->get();

        $activityColorMap = [];
        foreach ($schoolActivityTypes as $at) {
            $activityColorMap[mb_strtolower(trim($at->name))] = $at->color ?: '#6366F1';
        }

        // Apenas entradas de escalas PUBLICADAS (status = 'published')
        $entries = ScheduleEntry::whereBetween('date', [$startDate, $endDate])
            ->whereHas('schedule', fn ($q) => $q->where('school_id', $school->id)->where('status', 'published'))
            ->with(['schedule.assistant.user', 'activityType'])
            ->orderBy('start_time')
            ->get();

        $schoolAssistants = Assistant::where('school_id', $school->id)
            ->with(['user', 'assistantScheduleProfiles.shifts.shiftDays'])
            ->orderBy('internal_number')
            ->orderBy('id')
            ->get()
            ->keyBy(fn (Assistant $a) => (string) $a->id);

        foreach ($entries as $entry) {
            $assistant = $entry->schedule?->assistant;
            if ($assistant && ! $schoolAssistants->has((string) $assistant->id)) {
                $assistant->loadMissing(['user', 'assistantScheduleProfiles.shifts.shiftDays']);
                $schoolAssistants->put((string) $assistant->id, $assistant);
            }
        }

        if ($assistantFilterId !== null && $assistantFilterId !== '' && $assistantFilterId !== 'all') {
            if (! $schoolAssistants->has((string) $assistantFilterId)) {
                $extraAssistant = Assistant::with(['user', 'assistantScheduleProfiles.shifts.shiftDays'])
                    ->find($assistantFilterId);
                if ($extraAssistant) {
                    $schoolAssistants->put((string) $extraAssistant->id, $extraAssistant);
                }
            }

            $schoolAssistants = $schoolAssistants
                ->filter(fn (Assistant $a) => (string) $a->id === (string) $assistantFilterId)
                ->keyBy(fn (Assistant $a) => (string) $a->id);
        }

        $assistantIds = $schoolAssistants->map(fn (Assistant $a) => (string) $a->id)->values()->all();

        $absences = Absence::whereDate('valid_from', '<=', $endDate)
            ->whereDate('valid_until', '>=', $startDate)
            ->whereIn('assistant_id', $assistantIds)
            ->get();

        $entriesByDate = $entries->groupBy(fn (ScheduleEntry $e) => is_string($e->date) ? substr($e->date, 0, 10) : $e->date->format('Y-m-d'));

        $daysResult = [];

        foreach ($operatingDays as $opDay) {
            $dateStr = (string) $opDay['date'];
            $carbonDate = Carbon::parse($dateStr);
            $dayName = self::DAY_NAMES[$carbonDate->dayOfWeek] ?? '';
            $monthName = self::MONTH_NAMES[(int) $carbonDate->format('m')] ?? '';
            $formattedDate = sprintf(
                '%s, %s de %s de %s',
                $dayName,
                $carbonDate->format('d'),
                $monthName,
                $carbonDate->format('Y')
            );

            if (! $opDay['is_open']) {
                $daysResult[] = [
                    'date' => $dateStr,
                    'day_name' => $dayName,
                    'formatted_date' => $formattedDate,
                    'is_open' => false,
                    'closed_reason' => $opDay['reason'] ?? 'Estabelecimento Encerrado',
                    'open_time' => null,
                    'close_time' => null,
                    'time_headers' => [],
                    'quarter_ticks' => [],
                    'legend_activities' => [],
                    'rows' => [],
                ];

                continue;
            }

            $openTime = $opDay['opening_time'] ?: '07:30';
            $closeTime = $opDay['closing_time'] ?: '18:00';
            $winStartMin = $this->timeToMin($openTime);
            $winEndMin = $this->timeToMin($closeTime);
            $winTotalMin = max(60, $winEndMin - $winStartMin);

            $timeHeaders = [];
            for ($m = $winStartMin; $m < $winEndMin; $m += 30) {
                $hh = str_pad((string) floor($m / 60), 2, '0', STR_PAD_LEFT);
                $mm = str_pad((string) ($m % 60), 2, '0', STR_PAD_LEFT);
                $timeHeaders[] = [
                    'label' => "$hh:$mm",
                    'left' => round((($m - $winStartMin) / $winTotalMin) * 100, 3),
                    'width' => round((30 / $winTotalMin) * 100, 3),
                    'is_hour' => ($m % 60 === 0),
                ];
            }

            $quarterTicks = [];
            for ($m = $winStartMin; $m <= $winEndMin; $m += 15) {
                $quarterTicks[] = [
                    'left' => round((($m - $winStartMin) / $winTotalMin) * 100, 3),
                    'is_hour' => ($m % 60 === 0),
                    'is_half' => ($m % 30 === 0),
                ];
            }

            $dayEntries = $entriesByDate->get($dateStr, collect());
            $byAssistant = $dayEntries->groupBy(fn (ScheduleEntry $e) => (string) $e->schedule->assistant_id);

            $absencesOnDate = $absences->filter(function (Absence $ab) use ($dateStr) {
                $from = $ab->valid_from ? $ab->valid_from->format('Y-m-d') : '';
                $until = $ab->valid_until ? $ab->valid_until->format('Y-m-d') : $from;

                return $from <= $dateStr && $until >= $dateStr;
            })->keyBy(fn (Absence $ab) => (string) $ab->assistant_id);

            $workingRows = [];
            $absentRows = [];
            $legendMap = [];

            foreach ($schoolAssistants as $assistant) {
                if (! $assistant || ! $assistant->user) {
                    continue;
                }

                $assistantId = (string) $assistant->id;
                $fullName = trim(($assistant->user->first_name ?? '') . ' ' . ($assistant->user->last_name ?? ''));
                $code = $assistant->internal_number ?? '';
                $absenceRecord = $absencesOnDate->get($assistantId);
                $isFullDayAbsent = $absenceRecord !== null && (empty($absenceRecord->start_time) || empty($absenceRecord->end_time));
                $aEntries = $byAssistant->get($assistantId, collect());

                if ($aEntries->isEmpty() && $absenceRecord === null) {
                    continue;
                }

                if ($isFullDayAbsent || ($absenceRecord !== null && $aEntries->isEmpty())) {
                    [$shiftStart, $shiftEnd] = $this->resolveDefaultShift($assistant, $dateStr, (int) $opDay['day_of_week']);
                    $sMin = max($winStartMin, $this->timeToMin($shiftStart));
                    $eMin = min($winEndMin, $this->timeToMin($shiftEnd));
                    if ($eMin <= $sMin) {
                        $sMin = max($winStartMin, $this->timeToMin('08:30'));
                        $eMin = min($winEndMin, $this->timeToMin('16:30'));
                    }

                    $absentRows[] = [
                        'name' => $fullName,
                        'code' => $code,
                        'is_absent' => true,
                        'shift' => "$shiftStart – $shiftEnd",
                        'lunch' => null,
                        'work_blocks' => [],
                        'timeline_blocks' => [
                            [
                                'left' => round((($sMin - $winStartMin) / $winTotalMin) * 100, 3),
                                'width' => round((max(30, $eMin - $sMin) / $winTotalMin) * 100, 3),
                                'label' => 'AUSENTE',
                                'type' => 'absent',
                            ],
                        ],
                    ];

                    continue;
                }

                $sorted = $aEntries->sortBy('start_time')->values();
                $mergedEntries = [];
                foreach ($sorted as $entry) {
                    $st = substr((string) $entry->start_time, 0, 5);
                    $et = substr((string) $entry->end_time, 0, 5);
                    $actName = (string) ($entry->activity_name ?: ($entry->activityType?->name ?? 'Atividade'));
                    $color = $entry->activityType?->color
                        ?? $activityColorMap[mb_strtolower(trim($actName))]
                        ?? '#6366F1';

                    $lastIdx = count($mergedEntries) - 1;
                    if (
                        $lastIdx >= 0
                        && $mergedEntries[$lastIdx]['end'] === $st
                        && mb_strtolower($mergedEntries[$lastIdx]['activity']) === mb_strtolower($actName)
                    ) {
                        $mergedEntries[$lastIdx]['end'] = $et;
                    } else {
                        $mergedEntries[] = [
                            'start' => $st,
                            'end' => $et,
                            'activity' => $actName,
                            'color' => $color,
                        ];
                    }
                }

                $shiftStart = $mergedEntries[0]['start'];
                $shiftEnd = $mergedEntries[count($mergedEntries) - 1]['end'];
                $lunchStr = null;

                $timelineBlocks = [];
                $workBlocks = [];
                $prevEnd = null;

                foreach ($mergedEntries as $mEntry) {
                    $st = $mEntry['start'];
                    $et = $mEntry['end'];
                    $actName = $mEntry['activity'];
                    $color = $mEntry['color'];

                    if ($prevEnd !== null && $this->timeToMin($st) > $this->timeToMin($prevEnd)) {
                        $gapMin = $this->timeToMin($st) - $this->timeToMin($prevEnd);
                        if ($gapMin >= 30) {
                            $lunchStr = "$prevEnd – $st";
                            $timelineBlocks[] = [
                                'left' => round((($this->timeToMin($prevEnd) - $winStartMin) / $winTotalMin) * 100, 3),
                                'width' => round(($gapMin / $winTotalMin) * 100, 3),
                                'start' => $prevEnd,
                                'end' => $st,
                                'dur' => $gapMin,
                                'label' => 'Almoço',
                                'type' => 'lunch',
                            ];
                        }
                    }

                    $durMin = max(15, $this->timeToMin($et) - $this->timeToMin($st));

                    if (! isset($legendMap[$actName])) {
                        $legendMap[$actName] = [
                            'name' => $actName,
                            'color' => $color,
                            'bg' => $this->tintHex($color, 0.85),
                            'border' => $this->tintHex($color, 0.55),
                        ];
                    }

                    $workBlocks[] = [
                        'start' => $st,
                        'end' => $et,
                        'full_activity' => $actName,
                    ];

                    $timelineBlocks[] = [
                        'left' => max(0, round((($this->timeToMin($st) - $winStartMin) / $winTotalMin) * 100, 3)),
                        'width' => round(($durMin / $winTotalMin) * 100, 3),
                        'start' => $st,
                        'end' => $et,
                        'dur' => $durMin,
                        'label' => $this->shortActivityLabel($actName, $durMin),
                        'type' => 'work',
                        'color' => $color,
                        'bg' => $this->tintHex($color, 0.85),
                        'border' => $this->tintHex($color, 0.55),
                        'text' => $this->darkenHex($color, 0.45),
                    ];

                    $prevEnd = $et;
                }

                $workingRows[] = [
                    'name' => $fullName,
                    'code' => $code,
                    'is_absent' => false,
                    'shift' => "$shiftStart – $shiftEnd",
                    'lunch' => $lunchStr,
                    'work_blocks' => $workBlocks,
                    'timeline_blocks' => $timelineBlocks,
                ];
            }

            $rows = array_merge($workingRows, $absentRows);

            $daysResult[] = [
                'date' => $dateStr,
                'day_name' => $dayName,
                'formatted_date' => $formattedDate,
                'is_open' => true,
                'closed_reason' => null,
                'open_time' => $openTime,
                'close_time' => $closeTime,
                'time_headers' => $timeHeaders,
                'quarter_ticks' => $quarterTicks,
                'legend_activities' => array_values($legendMap),
                'rows' => $rows,
            ];
        }

        return $daysResult;
    }

    /**
     * Devolve o turno contratual [entry_time, exit_time] se o assistente tiver turno neste dia da semana,
     * ou null caso seja dia de folga semanal do assistente.
     *
     * @return array{0: string, 1: string}|null
     */
    private function resolveShiftForDay(Assistant $assistant, string $dateStr, int $weekday): ?array
    {
        $profile = $assistant->assistantScheduleProfiles
            ->filter(function ($p) use ($dateStr) {
                $from = $p->valid_from ? $p->valid_from->format('Y-m-d') : '0000-01-01';
                $until = $p->valid_until ? $p->valid_until->format('Y-m-d') : '9999-12-31';

                return $from <= $dateStr && $until >= $dateStr;
            })
            ->sortByDesc('valid_from')
            ->first();

        if (! $profile) {
            $profile = $assistant->assistantScheduleProfiles->sortByDesc('valid_from')->first();
        }

        if ($profile) {
            foreach ($profile->shifts as $shift) {
                $matchesDay = $shift->shiftDays->contains('day_of_week', $weekday);
                if ($matchesDay && $shift->entry_time && $shift->exit_time) {
                    return [substr((string) $shift->entry_time, 0, 5), substr((string) $shift->exit_time, 0, 5)];
                }
            }

            return null;
        }

        return ($weekday >= 0 && $weekday <= 4) ? ['08:30', '16:30'] : null;
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function resolveDefaultShift(Assistant $assistant, string $dateStr, int $weekday): array
    {
        return $this->resolveShiftForDay($assistant, $dateStr, $weekday) ?? ['08:30', '16:30'];
    }

    private function timeToMin(string $t): int
    {
        $parts = explode(':', substr($t, 0, 5));
        $h = (int) ($parts[0] ?? 0);
        $m = (int) ($parts[1] ?? 0);

        return $h * 60 + $m;
    }

    private function tintHex(string $hex, float $ratio = 0.86): string
    {
        $hex = ltrim($hex, '#');
        if (strlen($hex) !== 6) {
            return '#f8fafc';
        }
        $r = hexdec(substr($hex, 0, 2));
        $g = hexdec(substr($hex, 2, 2));
        $b = hexdec(substr($hex, 4, 2));
        $r = (int) round($r + (255 - $r) * $ratio);
        $g = (int) round($g + (255 - $g) * $ratio);
        $b = (int) round($b + (255 - $b) * $ratio);

        return sprintf('#%02x%02x%02x', $r, $g, $g ? $b : $b);
    }

    private function darkenHex(string $hex, float $ratio = 0.35): string
    {
        $hex = ltrim($hex, '#');
        if (strlen($hex) !== 6) {
            return '#1e293b';
        }
        $r = (int) round(hexdec(substr($hex, 0, 2)) * (1 - $ratio));
        $g = (int) round(hexdec(substr($hex, 2, 2)) * (1 - $ratio));
        $b = (int) round(hexdec(substr($hex, 4, 2)) * (1 - $ratio));

        return sprintf('#%02x%02x%02x', $r, $g, $b);
    }

    private function shortActivityLabel(string $name, int $durMin): string
    {
        if ($durMin <= 15) {
            $map = [
                'Acolhimento' => 'Acol.',
                'Apoio Escola' => 'Apoio',
                'Apoio Turmas/escola' => 'Turmas',
                'Entrega de alunos' => 'Entr.',
                'Intervalo' => 'Interv.',
                'Limpeza' => 'Limp.',
                'Vigilância almoço' => 'Vigil.',
                'Apoio NEE' => 'NEE',
            ];

            return $map[$name] ?? mb_substr($name, 0, 5) . '.';
        }

        if ($durMin <= 30) {
            $map = [
                'Acolhimento' => 'Acolhim.',
                'Apoio Escola' => 'Apoio',
                'Apoio Turmas/escola' => 'Turmas',
                'Entrega de alunos' => 'Entrega',
                'Intervalo' => 'Intervalo',
                'Limpeza' => 'Limpeza',
                'Vigilância almoço' => 'Vigilância',
                'Apoio NEE' => 'Apoio NEE',
            ];

            return $map[$name] ?? mb_substr($name, 0, 8);
        }

        return $name;
    }
}
