<!DOCTYPE html>
<html lang="pt">
<head>
<meta charset="UTF-8">
<title>Horário Individual de Serviço — {{ $school->name }}</title>
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

  /* Header */
  .header-table {
    width: 100%;
    border-collapse: collapse;
    border-bottom: 1.4pt solid #0f172a;
    margin-bottom: 7pt;
  }
  .header-table td {
    vertical-align: bottom;
    padding: 0 0 6pt 0;
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
    font-size: 6.8pt;
    color: #64748b;
    text-align: right;
    margin: 2pt 0 0 0;
    white-space: nowrap;
  }

  /* Legend Bar */
  .legend-bar {
    margin-bottom: 7pt;
    padding: 4.5pt 7pt;
    background-color: #f8fafc;
    border: 0.5pt solid #cbd5e1;
    border-radius: 2pt;
    font-size: 6.3pt;
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
    margin-right: 10pt;
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

  /* Unified Fixed-Timeline Schedule Table */
  .schedule-table {
    width: 100%;
    border-collapse: collapse;
  }
  .schedule-table thead th {
    border-top: 0.9pt solid #0f172a;
    border-bottom: 0.9pt solid #0f172a;
    background-color: #f8fafc;
    font-size: 5.8pt;
    font-weight: bold;
    white-space: nowrap;
    text-transform: uppercase;
    letter-spacing: 0.3pt;
    color: #334155;
    padding: 0;
    vertical-align: middle;
  }
  .schedule-table tbody td {
    border-bottom: 0.5pt solid #cbd5e1;
    padding: 0;
    vertical-align: middle;
  }

  .col-assistant {
    width: 11.5%;
    border-right: 0.5pt solid #cbd5e1;
    padding: 0 6pt !important;
    text-align: left;
  }
  .col-shift {
    width: 9.5%;
    border-right: 0.5pt solid #cbd5e1;
    padding: 0 6pt !important;
    text-align: left;
  }
  .col-timeline {
    width: 79%;
    padding: 0 !important;
  }

  .assistant-name {
    font-size: 7.5pt;
    font-weight: bold;
    color: #0f172a;
    display: block;
  }
  .assistant-code {
    font-size: 6pt;
    color: #64748b;
    margin-top: 2pt;
    display: block;
  }

  .shift-main {
    font-size: 7pt;
    font-weight: bold;
    color: #0f172a;
    display: block;
  }
  .shift-lunch {
    font-size: 5.5pt;
    color: #64748b;
    margin-top: 2pt;
    display: block;
  }
  .shift-unpublished {
    font-size: 5.3pt;
    font-weight: bold;
    color: #b45309;
    margin-top: 2pt;
    display: block;
  }

  .row-day-off td {
    background-color: #f8fafc;
  }
  .row-day-off .assistant-name,
  .row-day-off .shift-main {
    color: #475569;
  }

  .tl-header-wrap {
    position: relative;
    width: 100%;
    height: 19pt;
  }
  .tl-hour-header {
    position: absolute;
    top: 0;
    height: 19pt;
    border-left: 0.5pt solid #cbd5e1;
  }
  .tl-hour-text {
    padding-top: 5.5pt;
    padding-left: 2.5pt;
    font-size: 5.8pt;
    font-weight: bold;
    color: #334155;
    line-height: 8pt;
  }

  .tl-row-wrap {
    position: relative;
    width: 100%;
    height: {{ $rowHeightPt }}pt;
    background-color: #ffffff;
  }
  .row-day-off .tl-row-wrap {
    background-color: #f8fafc;
  }
  .tl-tick-line {
    position: absolute;
    top: 0;
    height: {{ $rowHeightPt }}pt;
    width: 0;
  }
  .tl-tick-hour {
    border-left: 0.5pt solid #e2e8f0;
  }
  .tl-tick-half {
    border-left: 0.5pt dashed #e2e8f0;
  }
  .tl-tick-quarter {
    border-left: 0.4pt dotted #f1f5f9;
  }

  .tl-block {
    position: absolute;
    top: {{ $blockTopPt }}pt;
    height: {{ $blockHeightPt }}pt;
    padding: 0;
    margin: 0;
    border-radius: 2pt;
    border-top-width: 2.6pt;
    border-top-style: solid;
    border-bottom-width: 0.6pt;
    border-bottom-style: solid;
    border-left-width: 0.4pt;
    border-left-style: solid;
    border-right-width: 0.4pt;
    border-right-style: solid;
    overflow: hidden;
  }

  .tl-inner-normal {
    padding: {{ $innerPadTopNormal }}pt 2pt 0 3pt;
  }
  .tl-inner-30 {
    padding: {{ $innerPadTopNormal }}pt 1pt 0 1.5pt;
  }
  .tl-inner-15 {
    padding: {{ $innerPadTop15 }}pt 0.4pt 0 0.4pt;
    text-align: center;
  }
  .tl-inner-lunch {
    padding: {{ $innerPadTopNormal }}pt 1.5pt 0 1.5pt;
    text-align: center;
  }
  .tl-inner-absent {
    padding-top: {{ $innerPadTopCenter }}pt;
    text-align: center;
    font-size: 6.2pt;
    font-weight: bold;
    color: #64748b;
    letter-spacing: 0.8pt;
    line-height: 9pt;
  }
  .tl-inner-dayoff {
    padding-top: {{ $innerPadTopCenter }}pt;
    text-align: center;
    font-size: 6pt;
    font-weight: bold;
    color: #64748b;
    letter-spacing: 0.9pt;
    line-height: 9pt;
  }
  .tl-inner-unpublished {
    padding-top: {{ $innerPadTopCenter }}pt;
    text-align: center;
    font-size: 5.8pt;
    font-weight: bold;
    color: #b45309;
    letter-spacing: 0.6pt;
    line-height: 9pt;
  }

  .tl-block-time {
    font-size: 4.8pt;
    color: #475569;
    display: block;
    line-height: 6pt;
    white-space: nowrap;
  }
  .tl-block-title {
    font-size: 5.8pt;
    font-weight: bold;
    display: block;
    line-height: 7.2pt;
    margin-top: 2pt;
    white-space: nowrap;
  }

  .tl-inner-30 .tl-block-time {
    font-size: 3.9pt;
    line-height: 5.2pt;
  }
  .tl-inner-30 .tl-block-title {
    font-size: 4.7pt;
    line-height: 6.2pt;
    margin-top: 1.8pt;
  }

  .tl-inner-15 .tl-block-time {
    font-size: 3.9pt;
    line-height: 4.6pt;
  }
  .tl-inner-15 .tl-block-title {
    font-size: 4.1pt;
    line-height: 5pt;
    margin-top: 1.2pt;
  }

  .tl-block-lunch {
    background-color: #f8fafc;
    border-top-color: #94a3b8;
    border-bottom-color: #cbd5e1;
    border-left-color: #cbd5e1;
    border-right-color: #cbd5e1;
    border-bottom-style: dashed;
    border-left-style: dashed;
    border-right-style: dashed;
  }
  .tl-block-lunch .tl-block-time {
    color: #64748b;
  }
  .tl-block-lunch .tl-block-title {
    color: #475569;
    font-weight: normal;
  }

  .tl-block-absent {
    background-color: #f8fafc;
    border-top-color: #94a3b8;
    border-bottom-color: #cbd5e1;
    border-left-color: #cbd5e1;
    border-right-color: #cbd5e1;
  }

  .tl-block-dayoff {
    background-color: #f1f5f9;
    border-top-color: #cbd5e1;
    border-bottom-color: #cbd5e1;
    border-left-color: #cbd5e1;
    border-right-color: #cbd5e1;
    border-bottom-style: dashed;
    border-left-style: dashed;
    border-right-style: dashed;
  }

  .tl-block-unpublished {
    background-color: #fffbeb;
    border-top-color: #f59e0b;
    border-bottom-color: #fcd34d;
    border-left-color: #fcd34d;
    border-right-color: #fcd34d;
    border-bottom-style: dashed;
    border-left-style: dashed;
    border-right-style: dashed;
  }

  .closed-box {
    border: 0.5pt solid #cbd5e1;
    background-color: #f8fafc;
    border-radius: 3pt;
    padding: 18pt;
    text-align: center;
    color: #64748b;
    font-size: 8pt;
    font-weight: bold;
    margin-top: 12pt;
  }

  /* Footer fixed at bottom */
  .footer {
    position: fixed;
    bottom: -4mm;
    left: 0;
    right: 0;
    border-top: 0.4pt solid #cbd5e1;
    padding-top: 2.5pt;
    font-size: 5.5pt;
    color: #64748b;
  }
  .footer-table {
    width: 100%;
    border-collapse: collapse;
  }
  .footer-table td {
    padding: 0;
  }
</style>
</head>
<body>

  <!-- FOOTER -->
  <div class="footer">
    <table class="footer-table">
      <tr>
        <td style="text-align:left;">Impresso em {{ $printedAt }}</td>
        <td style="text-align:right;">{{ $appVersion }}</td>
      </tr>
    </table>
  </div>

  @if (empty($sheets))
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
          <div class="school-title">{{ $school->name }} — Horário Individual de Serviço</div>
          <div class="school-subtitle">Assistentes Operacionais{{ $school->address ? ' · ' . $school->address : '' }}</div>
        </td>
        <td style="width: 300pt; white-space: nowrap;">
          <div class="date-title">{{ $periodLabel }}</div>
          <div class="hours-subtitle">Funcionamento: {{ $openTime }} – {{ $closeTime }}</div>
        </td>
      </tr>
    </table>
    <div class="closed-box">
      Não existe nenhuma escala publicada para os parâmetros selecionados.
    </div>
  @else
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
            <div class="school-title">{{ $school->name }} — Horário Individual de Serviço</div>
            <div class="school-subtitle">
              <span class="assistant-badge">{{ $sheet['assistant_name'] }}{{ $sheet['assistant_code'] ? ' (' . $sheet['assistant_code'] . ')' : '' }}</span>
              · Assistente Operacional{{ $school->address ? ' · ' . $school->address : '' }}
            </div>
          </td>
          <td style="width: 300pt; white-space: nowrap;">
            <div class="date-title">{{ $periodLabel }}</div>
            <div class="hours-subtitle">Funcionamento: {{ $openTime }} – {{ $closeTime }}</div>
          </td>
        </tr>
      </table>

      <!-- LEGENDA -->
      <div class="legend-bar">
        <span class="legend-label">Legenda:</span>
        @foreach ($sheet['legend_activities'] as $act)
          <span class="legend-item">
            <span class="legend-swatch" style="background-color: {{ $act['bg'] }}; border: 0.4pt solid {{ $act['border'] }}; border-top: 1.8pt solid {{ $act['color'] }};"></span>{{ $act['name'] }}
          </span>
        @endforeach
        <span class="legend-item">
          <span class="legend-swatch" style="background-color: #f8fafc; border: 0.4pt dashed #94a3b8; border-top: 1.8pt solid #94a3b8;"></span>Almoço
        </span>
        <span class="legend-item">
          <span class="legend-swatch" style="background-color: #f1f5f9; border: 0.4pt dashed #cbd5e1; border-top: 1.8pt solid #cbd5e1;"></span>Folga / Encerrado
        </span>
        @if (!empty($sheet['has_unpublished']))
          <span class="legend-item">
            <span class="legend-swatch" style="background-color: #fffbeb; border: 0.4pt dashed #f59e0b; border-top: 1.8pt solid #f59e0b;"></span>Sem Horário Publicado
          </span>
        @endif
        <span class="legend-item">
          <span class="legend-swatch" style="background-color: #f8fafc; border: 0.4pt solid #cbd5e1; border-top: 1.8pt solid #94a3b8;"></span>Ausente
        </span>
      </div>

      <!-- TABELA COM TIMELINE FIXA SEMANAL -->
      <table class="schedule-table">
        <thead>
          <tr>
            <th class="col-assistant" style="padding: 5pt 6pt !important;">Dia / Data</th>
            <th class="col-shift" style="padding: 5pt 6pt !important;">Turno / Almoço</th>
            <th class="col-timeline">
              <div class="tl-header-wrap">
                @foreach ($timeHeaders as $idx => $th)
                  <div class="tl-hour-header" style="left: {{ $th['left'] }}%; width: {{ $th['width'] }}%; {{ $idx === 0 ? 'border-left:none;' : '' }}">
                    <div class="tl-hour-text">{{ $th['label'] }}</div>
                  </div>
                @endforeach
              </div>
            </th>
          </tr>
        </thead>
        <tbody>
          @foreach ($sheet['day_rows'] as $r)
            <tr class="{{ $r['is_day_off'] ? 'row-day-off' : '' }}">
              <td class="col-assistant">
                <span class="assistant-name">{{ $r['day_name'] }}</span>
                <span class="assistant-code">{{ $r['date_formatted'] }}</span>
              </td>
              <td class="col-shift">
                <span class="shift-main">{{ $r['shift'] }}</span>
                @if (!empty($r['lunch']))
                  <span class="{{ !empty($r['is_unpublished']) ? 'shift-unpublished' : 'shift-lunch' }}">{{ $r['lunch'] }}</span>
                @endif
              </td>
              <td class="col-timeline">
                <div class="tl-row-wrap">
                  @foreach ($quarterTicks as $tIdx => $qt)
                    @if ($tIdx > 0 && $tIdx < count($quarterTicks) - 1)
                      <div class="tl-tick-line {{ $qt['is_hour'] ? 'tl-tick-hour' : ($qt['is_half'] ? 'tl-tick-half' : 'tl-tick-quarter') }}" style="left: {{ $qt['left'] }}%;"></div>
                    @endif
                  @endforeach

                  @foreach ($r['timeline_blocks'] as $tb)
                    @if ($tb['type'] === 'day_off')
                      <div class="tl-block tl-block-dayoff" style="left: {{ $tb['left'] }}%; width: {{ $tb['width'] }}%;">
                        <div class="tl-inner-dayoff">{{ $tb['label'] }}</div>
                      </div>
                    @elseif ($tb['type'] === 'unpublished')
                      <div class="tl-block tl-block-unpublished" style="left: {{ $tb['left'] }}%; width: {{ $tb['width'] }}%;">
                        <div class="tl-inner-unpublished">{{ $tb['label'] }}</div>
                      </div>
                    @elseif ($tb['type'] === 'absent')
                      <div class="tl-block tl-block-absent" style="left: {{ $tb['left'] }}%; width: {{ $tb['width'] }}%;">
                        <div class="tl-inner-absent">{{ $tb['label'] }}</div>
                      </div>
                    @elseif ($tb['type'] === 'lunch')
                      <div class="tl-block tl-block-lunch" style="left: {{ $tb['left'] }}%; width: {{ $tb['width'] }}%;">
                        <div class="tl-inner-lunch">
                          <span class="tl-block-time">{{ $tb['start'] }}-{{ $tb['end'] }}</span>
                          <span class="tl-block-title">Almoço</span>
                        </div>
                      </div>
                    @else
                      @php
                        $is15 = ($tb['dur'] <= 15);
                        $is30 = (!$is15 && $tb['dur'] <= 30);
                      @endphp
                      <div class="tl-block" style="left: {{ $tb['left'] }}%; width: {{ $tb['width'] }}%; background-color: {{ $tb['bg'] }}; border-bottom-color: {{ $tb['border'] }}; border-left-color: {{ $tb['border'] }}; border-right-color: {{ $tb['border'] }}; border-top-color: {{ $tb['color'] }};">
                        @if ($is15)
                          <div class="tl-inner-15">
                            <span class="tl-block-time">{!! $tb['start'] . '<br>' . $tb['end'] !!}</span>
                            <span class="tl-block-title" style="color: {{ $tb['text'] }};">{{ $tb['label'] }}</span>
                          </div>
                        @elseif ($is30)
                          <div class="tl-inner-30">
                            <span class="tl-block-time">{{ $tb['start'] }}-{{ $tb['end'] }}</span>
                            <span class="tl-block-title" style="color: {{ $tb['text'] }};">{{ $tb['label'] }}</span>
                          </div>
                        @else
                          <div class="tl-inner-normal">
                            <span class="tl-block-time">{{ $tb['start'] }}-{{ $tb['end'] }}</span>
                            <span class="tl-block-title" style="color: {{ $tb['text'] }};">{{ $tb['label'] }}</span>
                          </div>
                        @endif
                      </div>
                    @endif
                  @endforeach
                </div>
              </td>
            </tr>
          @endforeach
        </tbody>
      </table>

      @if ($sheetIdx < count($sheets) - 1)
        <div class="page-break"></div>
      @endif
    @endforeach
  @endif

</body>
</html>
