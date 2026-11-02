<!DOCTYPE html>
<html lang="pt">
<head>
<meta charset="UTF-8">
<title>Horário Mensal de Serviço — {{ $school->name }}</title>
<style>
  @page {
    size: A4 landscape;
    margin: 9mm 10mm 10mm 10mm;
  }
  body {
    font-family: DejaVu Sans, Helvetica, Arial, sans-serif;
    font-size: 8pt;
    color: #0f172a;
    margin: 0;
    padding: 0;
  }
  .page-break {
    page-break-after: always;
  }

  /* Header (idêntico aos layouts Diário e Semanal) */
  .header-table {
    width: 100%;
    border-collapse: collapse;
    border-bottom: 1.4pt solid #0f172a;
    margin-bottom: 6pt;
  }
  .header-table td {
    vertical-align: bottom;
    padding: 0 0 5pt 0;
  }
  .school-title {
    font-size: 11pt;
    font-weight: bold;
    color: #0f172a;
    letter-spacing: 0.1pt;
    margin: 0;
  }
  .school-subtitle {
    font-size: 7.2pt;
    color: #475569;
    margin: 2pt 0 0 0;
  }
  .assistant-badge {
    font-weight: bold;
    color: #0f172a;
  }
  .date-title {
    font-size: 9.2pt;
    font-weight: bold;
    color: #0f172a;
    text-align: right;
    margin: 0;
    white-space: nowrap;
  }
  .hours-subtitle {
    font-size: 6.6pt;
    color: #64748b;
    text-align: right;
    margin: 2pt 0 0 0;
    white-space: nowrap;
  }

  /* Legend Bar */
  .legend-bar {
    margin-bottom: 6pt;
    padding: 4pt 7pt;
    background-color: #f8fafc;
    border: 0.5pt solid #cbd5e1;
    border-radius: 2pt;
    font-size: 6.2pt;
    color: #334155;
  }
  .legend-label {
    font-weight: bold;
    text-transform: uppercase;
    color: #475569;
    margin-right: 7pt;
    letter-spacing: 0.3pt;
  }
  .legend-item {
    display: inline-block;
    margin-right: 12pt;
    color: #1e293b;
  }
  .legend-swatch {
    display: inline-block;
    width: 8pt;
    height: 6pt;
    border-radius: 1pt;
    margin-right: 3.5pt;
    vertical-align: middle;
  }

  /* Calendar Table */
  .calendar-table {
    width: 100%;
    border-collapse: collapse;
    table-layout: fixed;
  }
  .calendar-table thead th {
    width: 14.2857%;
    border-top: 0.9pt solid #0f172a;
    border-bottom: 0.9pt solid #0f172a;
    border-right: 0.5pt solid #cbd5e1;
    background-color: #f8fafc;
    font-size: 6pt;
    font-weight: bold;
    text-transform: uppercase;
    letter-spacing: 0.3pt;
    color: #334155;
    padding: 4.5pt 5pt;
    text-align: left;
  }
  .calendar-table thead th.last-col {
    border-right: none;
  }
  .calendar-table tbody td {
    width: 14.2857%;
    border-bottom: 0.5pt solid #cbd5e1;
    border-right: 0.5pt solid #cbd5e1;
    vertical-align: top;
    padding: 3.5pt 4.5pt 4pt 4.5pt;
  }
  .calendar-table tbody td.last-col {
    border-right: none;
  }
  .calendar-table tbody td.cell-off {
    background-color: #f8fafc;
  }
  .calendar-table tbody td.cell-adj {
    background-color: #fcfdfe;
  }

  /* Day Header row inside each square */
  .day-header-table {
    width: 100%;
    border-collapse: collapse;
    margin-bottom: 3pt;
  }
  .day-header-table td {
    height: auto !important;
    border: none !important;
    padding: 0 !important;
    vertical-align: middle;
  }
  .day-num {
    font-size: 7.8pt;
    font-weight: bold;
    color: #0f172a;
  }
  .day-num-adj {
    font-size: 7.2pt;
    font-weight: bold;
    color: #475569;
  }
  .day-num-off {
    font-size: 7.8pt;
    font-weight: bold;
    color: #64748b;
  }
  .day-pill {
    font-size: 4.8pt;
    font-weight: bold;
    text-transform: uppercase;
    letter-spacing: 0.25pt;
    padding: 1pt 3.5pt;
    border-radius: 2pt;
  }
  .pill-working {
    background-color: #eff6ff;
    color: #1d4ed8;
    border: 0.4pt solid #bfdbfe;
  }
  .pill-absent {
    background-color: #fef2f2;
    color: #b91c1c;
    border: 0.4pt solid #fecaca;
  }
  .pill-unpublished {
    background-color: #fffbeb;
    color: #b45309;
    border: 0.4pt solid #fde68a;
  }
  .pill-off {
    background-color: #f1f5f9;
    color: #64748b;
    border: 0.4pt solid #cbd5e1;
  }

  /* Day Inner Card */
  .day-card {
    height: {{ $cardHeightPt }}pt;
    border-radius: 2pt;
    border-top-width: 2.5pt;
    border-top-style: solid;
    border-bottom-width: 0.5pt;
    border-bottom-style: solid;
    border-left-width: 0.5pt;
    border-left-style: solid;
    border-right-width: 0.5pt;
    border-right-style: solid;
    padding: 0;
    overflow: hidden;
  }

  /* Working state */
  .card-working {
    background-color: #f8fafc;
    border-top-color: #2563eb;
    border-bottom-color: #cbd5e1;
    border-left-color: #cbd5e1;
    border-right-color: #cbd5e1;
  }
  .card-working-inner {
    padding: 3.5pt 5pt 0 5pt;
  }
  .shift-label {
    font-size: 4.6pt;
    font-weight: bold;
    text-transform: uppercase;
    letter-spacing: 0.3pt;
    color: #64748b;
    display: block;
  }
  .shift-hours {
    font-size: 7.8pt;
    font-weight: bold;
    color: #0f172a;
    margin-top: 0.5pt;
    display: block;
  }
  .lunch-box {
    margin-top: 3.5pt;
    padding: 2pt 4pt;
    background-color: #ffffff;
    border: 0.5pt dashed #94a3b8;
    border-radius: 1.5pt;
  }
  .lunch-label {
    font-size: 4.4pt;
    font-weight: bold;
    text-transform: uppercase;
    letter-spacing: 0.25pt;
    color: #64748b;
    display: block;
  }
  .lunch-hours {
    font-size: 6.2pt;
    font-weight: bold;
    color: #334155;
    margin-top: 0.5pt;
    display: block;
  }

  /* Absent state */
  .card-absent {
    background-color: #fef2f2;
    border-top-color: #ef4444;
    border-bottom-color: #fecaca;
    border-left-color: #fecaca;
    border-right-color: #fecaca;
  }
  .card-absent-inner {
    padding-top: {{ $centerPadTopPt }}pt;
    text-align: center;
  }
  .absent-title {
    font-size: 6pt;
    font-weight: bold;
    text-transform: uppercase;
    letter-spacing: 0.3pt;
    color: #b91c1c;
    display: block;
  }
  .absent-sub {
    font-size: 5.1pt;
    color: #7f1d1d;
    margin-top: 2.5pt;
    display: block;
  }

  /* Unpublished state */
  .card-unpublished {
    background-color: #fffbeb;
    border-top-color: #f59e0b;
    border-bottom-color: #fcd34d;
    border-left-color: #fcd34d;
    border-right-color: #fcd34d;
    border-bottom-style: dashed;
    border-left-style: dashed;
    border-right-style: dashed;
  }
  .card-unpublished-inner {
    padding-top: {{ max(6, $centerPadTopPt - 3) }}pt;
    text-align: center;
  }
  .unpublished-title {
    font-size: 5.4pt;
    font-weight: bold;
    text-transform: uppercase;
    letter-spacing: 0.25pt;
    color: #b45309;
    display: block;
  }
  .unpublished-sub {
    font-size: 5pt;
    color: #92400e;
    margin-top: 2.5pt;
    display: block;
  }

  /* Weekend / Holiday state */
  .card-off {
    background-color: #f8fafc;
    border-top-color: #94a3b8;
    border-bottom-color: #cbd5e1;
    border-left-color: #cbd5e1;
    border-right-color: #cbd5e1;
    border-bottom-style: dashed;
    border-left-style: dashed;
    border-right-style: dashed;
  }
  .card-off-inner {
    padding-top: {{ $centerPadTopPt }}pt;
    text-align: center;
  }
  .off-title {
    font-size: 5.6pt;
    font-weight: bold;
    text-transform: uppercase;
    letter-spacing: 0.3pt;
    color: #64748b;
    display: block;
  }
  .off-sub {
    font-size: 4.9pt;
    color: #94a3b8;
    margin-top: 2pt;
    display: block;
  }

  /* Footer */
  .footer {
    position: fixed;
    bottom: -3mm;
    left: 0;
    right: 0;
    border-top: 0.5pt solid #cbd5e1;
    padding-top: 3pt;
    font-size: 6pt;
    color: #64748b;
  }
  .footer-table {
    width: 100%;
    border-collapse: collapse;
  }
</style>
</head>
<body>

  <div class="footer">
    <table class="footer-table">
      <tr>
        <td style="text-align: left;">Impresso em {{ $printedAt }}</td>
        <td style="text-align: right;">{{ $appVersion ?? 'SGDE v1.0' }}</td>
      </tr>
    </table>
  </div>

  @foreach ($sheets as $sheetIdx => $sheet)
    <table class="header-table">
      <tr>
        <td style="width: 92pt;">
          @if (!empty($logoBase64))
            <img src="{{ $logoBase64 }}" style="height: 22pt; width: auto;" />
          @else
            <strong style="font-size: 12pt; color: #4c55a2;">SGDE</strong>
          @endif
        </td>
        <td>
          <div class="school-title">{{ $school->name }} — Horário Mensal de Serviço</div>
          <div class="school-subtitle">
            <span class="assistant-badge">{{ $sheet['assistant_name'] }}{{ !empty($sheet['assistant_code']) ? ' (' . $sheet['assistant_code'] . ')' : '' }}</span>
            · Assistente Operacional{{ $school->address ? ' · ' . $school->address : '' }}
          </div>
        </td>
        <td style="width: 300pt; white-space: nowrap;">
          <div class="date-title">{{ $monthYearTitle }}</div>
          <div class="hours-subtitle">{{ $sheet['period_subtitle'] }}</div>
        </td>
      </tr>
    </table>

    <div class="legend-bar">
      <span class="legend-label">Legenda:</span>
      <span class="legend-item">
        <span class="legend-swatch" style="background-color: #f8fafc; border: 0.4pt solid #cbd5e1; border-top: 1.8pt solid #2563eb;"></span>Turno de Serviço (Entrada – Saída)
      </span>
      <span class="legend-item">
        <span class="legend-swatch" style="background-color: #ffffff; border: 0.4pt dashed #94a3b8;"></span>Pausa de Almoço
      </span>
      <span class="legend-item">
        <span class="legend-swatch" style="background-color: #fef2f2; border: 0.4pt solid #fecaca; border-top: 1.8pt solid #ef4444;"></span>Falta / Ausência Programada
      </span>
      @if (!empty($sheet['has_unpublished']))
        <span class="legend-item">
          <span class="legend-swatch" style="background-color: #fffbeb; border: 0.4pt dashed #fcd34d; border-top: 1.8pt solid #f59e0b;"></span>Sem Horário Publicado
        </span>
      @endif
      <span class="legend-item">
        <span class="legend-swatch" style="background-color: #f8fafc; border: 0.4pt dashed #cbd5e1; border-top: 1.8pt solid #94a3b8;"></span>Folga Semanal / Feriado / Encerrado
      </span>
    </div>

    <table class="calendar-table">
      <thead>
        <tr>
          <th>Segunda-feira</th>
          <th>Terça-feira</th>
          <th>Quarta-feira</th>
          <th>Quinta-feira</th>
          <th>Sexta-feira</th>
          <th>Sábado</th>
          <th class="last-col">Domingo</th>
        </tr>
      </thead>
      <tbody>
        @foreach ($sheet['weeks'] as $week)
          <tr>
            @foreach ($week as $colIdx => $cell)
              @php
                $isLastCol = ($colIdx === 6);
                $tdClasses = [];
                if ($isLastCol) $tdClasses[] = 'last-col';
                if ($cell['state'] === 'weekend' || $cell['state'] === 'holiday') {
                    $tdClasses[] = 'cell-off';
                } elseif (!$cell['in_month']) {
                    $tdClasses[] = 'cell-adj';
                }
              @endphp
              <td class="{{ implode(' ', $tdClasses) }}">
                <table class="day-header-table">
                  <tr>
                    <td style="text-align: left;">
                      <span class="{{ ($cell['state'] === 'weekend' || $cell['state'] === 'holiday') ? 'day-num-off' : (!$cell['in_month'] ? 'day-num-adj' : 'day-num') }}">
                        {{ $cell['day_num'] }}
                      </span>
                    </td>
                    <td style="text-align: right;">
                      @if ($cell['state'] === 'working')
                        <span class="day-pill pill-working">{{ $cell['badge'] }}</span>
                      @elseif ($cell['state'] === 'absent')
                        <span class="day-pill pill-absent">{{ $cell['badge'] }}</span>
                      @elseif ($cell['state'] === 'unpublished')
                        <span class="day-pill pill-unpublished">{{ $cell['badge'] }}</span>
                      @elseif ($cell['state'] === 'weekend' || $cell['state'] === 'holiday')
                        <span class="day-pill pill-off">{{ $cell['badge'] }}</span>
                      @endif
                    </td>
                  </tr>
                </table>

                @if ($cell['state'] === 'working')
                  <div class="day-card card-working">
                    <div class="card-working-inner">
                      <span class="shift-label">Entrada – Saída</span>
                      <span class="shift-hours">{{ $cell['shift'] }}</span>
                      <div class="lunch-box">
                        <span class="lunch-label">Pausa de Almoço</span>
                        <span class="lunch-hours">{{ $cell['lunch'] }}</span>
                      </div>
                    </div>
                  </div>
                @elseif ($cell['state'] === 'absent')
                  <div class="day-card card-absent">
                    <div class="card-absent-inner">
                      <span class="absent-title">{{ $cell['shift'] }}</span>
                      <span class="absent-sub">{{ $cell['lunch'] }}</span>
                    </div>
                  </div>
                @elseif ($cell['state'] === 'unpublished')
                  <div class="day-card card-unpublished">
                    <div class="card-unpublished-inner">
                      <span class="unpublished-title">SEM HORÁRIO PUBLICADO</span>
                      <span class="unpublished-sub">Turno previsto: {{ $cell['shift'] }}</span>
                    </div>
                  </div>
                @elseif ($cell['state'] === 'weekend' || $cell['state'] === 'holiday')
                  <div class="day-card card-off">
                    <div class="card-off-inner">
                      <span class="off-title">{{ $cell['shift'] }}</span>
                      <span class="off-sub">{{ $cell['lunch'] }}</span>
                    </div>
                  </div>
                @endif
              </td>
            @endforeach
          </tr>
        @endforeach
      </tbody>
    </table>

    @if ($sheetIdx < count($sheets) - 1)
      <div class="page-break"></div>
    @endif
  @endforeach

</body>
</html>
