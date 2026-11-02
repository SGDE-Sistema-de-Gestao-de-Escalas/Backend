<!DOCTYPE html>
<html lang="pt">
<head>
<meta charset="UTF-8">
<title>Escala de Serviço — {{ $school->name }}</title>
<style>
  @page {
    size: A4 landscape;
    margin: 9mm 10mm 10mm 10mm;
  }
  body {
    font-family: DejaVu Sans, Helvetica, Arial, sans-serif;
    font-size: 7.5pt;
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
    border-bottom: 1.2pt solid #0f172a;
    margin-bottom: 6pt;
  }
  .header-table td {
    vertical-align: bottom;
    padding: 0 0 5pt 0;
  }
  .school-title {
    font-size: 10pt;
    font-weight: bold;
    color: #0f172a;
    letter-spacing: 0.1pt;
    margin: 0;
  }
  .school-subtitle {
    font-size: 6.4pt;
    color: #64748b;
    margin: 1.5pt 0 0 0;
  }
  .date-title {
    font-size: 8.5pt;
    font-weight: bold;
    color: #0f172a;
    text-align: right;
    margin: 0;
    white-space: nowrap;
  }
  .hours-subtitle {
    font-size: 6.4pt;
    color: #64748b;
    text-align: right;
    margin: 1.5pt 0 0 0;
    white-space: nowrap;
  }

  /* Legend Bar */
  .legend-bar {
    margin-bottom: 6pt;
    padding: 3.5pt 6pt;
    background-color: #f8fafc;
    border: 0.5pt solid #cbd5e1;
    border-radius: 2pt;
    font-size: 5.8pt;
    color: #334155;
  }
  .legend-label {
    font-weight: bold;
    text-transform: uppercase;
    color: #475569;
    margin-right: 6pt;
    letter-spacing: 0.3pt;
  }
  .legend-item {
    display: inline-block;
    margin-right: 9pt;
    color: #1e293b;
  }
  .legend-swatch {
    display: inline-block;
    width: 7pt;
    height: 5.5pt;
    border-radius: 1pt;
    margin-right: 3pt;
    vertical-align: middle;
  }

  /* Unified Fixed-Timeline Schedule Table */
  .schedule-table {
    width: 100%;
    border-collapse: collapse;
  }
  .schedule-table thead th {
    border-top: 0.8pt solid #0f172a;
    border-bottom: 0.8pt solid #0f172a;
    background-color: #f8fafc;
    font-size: 5.8pt;
    font-weight: bold;
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
    padding: 0 5pt !important;
    text-align: left;
  }
  .col-shift {
    width: 9.5%;
    border-right: 0.5pt solid #cbd5e1;
    padding: 0 5pt !important;
    text-align: left;
  }
  .col-timeline {
    width: 79%;
    padding: 0 !important;
  }

  .assistant-name {
    font-size: 6.6pt;
    font-weight: bold;
    color: #0f172a;
    display: block;
  }
  .assistant-code {
    font-size: 5.3pt;
    color: #64748b;
    margin-top: 1pt;
    display: block;
  }

  .shift-main {
    font-size: 6.2pt;
    font-weight: bold;
    color: #0f172a;
    display: block;
  }
  .shift-lunch {
    font-size: 5pt;
    color: #64748b;
    margin-top: 1.5pt;
    display: block;
  }

  /* Timeline Header Bar */
  .tl-header-wrap {
    position: relative;
    width: 100%;
    height: 17pt;
  }
  .tl-hour-header {
    position: absolute;
    top: 0;
    height: 17pt;
    border-left: 0.5pt solid #cbd5e1;
  }
  .tl-hour-text {
    padding-top: 4.5pt;
    padding-left: 2.5pt;
    font-size: 5.5pt;
    font-weight: bold;
    color: #334155;
    line-height: 8pt;
  }

  /* Timeline Row Track: fixed 32pt height */
  .tl-row-wrap {
    position: relative;
    width: 100%;
    height: 32pt;
    background-color: #ffffff;
  }
  .tl-tick-line {
    position: absolute;
    top: 0;
    height: 32pt;
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

  /*
   * Strict Uniform Height for ALL blocks on the timeline:
   * top: 3pt + border-top: 2.2pt + height: 23.3pt + border-bottom: 0.5pt = 29.0pt
   * Leaves exact 3.0pt margin at top and 3.0pt margin at bottom inside 32pt row.
   */
  .tl-block {
    position: absolute;
    top: 3pt;
    height: 23.3pt;
    padding: 0;
    margin: 0;
    border-radius: 1.5pt;
    border-top-width: 2.2pt;
    border-top-style: solid;
    border-bottom-width: 0.5pt;
    border-bottom-style: solid;
    border-left-width: 0.4pt;
    border-left-style: solid;
    border-right-width: 0.4pt;
    border-right-style: solid;
    overflow: hidden;
  }

  .tl-inner-normal {
    padding: 2.2pt 1.5pt 0 2pt;
  }
  .tl-inner-30 {
    padding: 2.2pt 1pt 0 1.2pt;
  }
  .tl-inner-15 {
    padding: 1.2pt 0.4pt 0 0.4pt;
    text-align: center;
  }
  .tl-inner-lunch {
    padding: 2.2pt 1.5pt 0 1.5pt;
    text-align: center;
  }
  .tl-inner-absent {
    padding-top: 7pt;
    text-align: center;
    font-size: 5.6pt;
    font-weight: bold;
    color: #64748b;
    letter-spacing: 0.8pt;
    line-height: 8pt;
  }

  .tl-block-time {
    font-size: 4.3pt;
    color: #475569;
    display: block;
    line-height: 5.2pt;
    white-space: nowrap;
  }
  .tl-block-title {
    font-size: 5pt;
    font-weight: bold;
    display: block;
    line-height: 6.2pt;
    margin-top: 1.2pt;
    white-space: nowrap;
  }

  .tl-inner-30 .tl-block-time {
    font-size: 3.85pt;
    line-height: 4.8pt;
  }
  .tl-inner-30 .tl-block-title {
    font-size: 4.55pt;
    line-height: 5.8pt;
    margin-top: 1.2pt;
  }

  .tl-inner-15 .tl-block-time {
    font-size: 3.8pt;
    line-height: 4.3pt;
  }
  .tl-inner-15 .tl-block-title {
    font-size: 4pt;
    line-height: 4.6pt;
    margin-top: 0.6pt;
  }

  /* Lunch block colors */
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

  /* Absent block colors (top border like all other blocks, same exact height) */
  .tl-block-absent {
    background-color: #f8fafc;
    border-top-color: #94a3b8;
    border-bottom-color: #cbd5e1;
    border-left-color: #cbd5e1;
    border-right-color: #cbd5e1;
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

  @foreach ($days as $dayIdx => $day)
    <!-- HEADER -->
    <table class="header-table">
      <tr>
        <td style="width: 88pt;">
          @if (!empty($logoBase64))
            <img src="{{ $logoBase64 }}" style="height: 20pt; width: auto;" />
          @else
            <strong style="font-size: 12pt; color: #4c55a2;">SGDE</strong>
          @endif
        </td>
        <td>
          <div class="school-title">{{ $school->name }} — {{ $docTitle }}</div>
          <div class="school-subtitle">Assistentes Operacionais{{ $school->address ? ' · ' . $school->address : '' }}</div>
        </td>
        <td style="width: 280pt; white-space: nowrap;">
          <div class="date-title">{{ $day['formatted_date'] }}</div>
          @if ($day['is_open'])
            <div class="hours-subtitle">Funcionamento: {{ $day['open_time'] }} – {{ $day['close_time'] }}</div>
          @else
            <div class="hours-subtitle">Estabelecimento Encerrado</div>
          @endif
        </td>
      </tr>
    </table>

    @if (!$day['is_open'])
      <div class="closed-box">
        {{ $day['closed_reason'] ?: 'Estabelecimento Encerrado' }}
      </div>
    @elseif (empty($day['rows']))
      <div class="closed-box">
        Sem escalas atribuídas para esta data.
      </div>
    @else
      <!-- LEGENDA DE ATIVIDADES -->
      <div class="legend-bar">
        <span class="legend-label">Legenda:</span>
        @foreach ($day['legend_activities'] as $act)
          <span class="legend-item">
            <span class="legend-swatch" style="background-color: {{ $act['bg'] }}; border: 0.4pt solid {{ $act['border'] }}; border-top: 1.8pt solid {{ $act['color'] }};"></span>{{ $act['name'] }}
          </span>
        @endforeach
        <span class="legend-item">
          <span class="legend-swatch" style="background-color: #f8fafc; border: 0.4pt dashed #94a3b8; border-top: 1.8pt solid #94a3b8;"></span>Almoço
        </span>
        <span class="legend-item">
          <span class="legend-swatch" style="background-color: #f8fafc; border: 0.4pt solid #cbd5e1; border-top: 1.8pt solid #94a3b8;"></span>Ausente
        </span>
      </div>

      <!-- TABELA COM TIMELINE FIXA -->
      <table class="schedule-table">
        <thead>
          <tr>
            <th class="col-assistant" style="padding: 4pt 5pt !important;">Assistente</th>
            <th class="col-shift" style="padding: 4pt 5pt !important;">Turno / Almoço</th>
            <th class="col-timeline">
              <div class="tl-header-wrap">
                @foreach ($day['time_headers'] as $idx => $th)
                  <div class="tl-hour-header" style="left: {{ $th['left'] }}%; width: {{ $th['width'] }}%; {{ $idx === 0 ? 'border-left:none;' : '' }}">
                    <div class="tl-hour-text">{{ $th['label'] }}</div>
                  </div>
                @endforeach
              </div>
            </th>
          </tr>
        </thead>
        <tbody>
          @foreach ($day['rows'] as $r)
            <tr>
              <td class="col-assistant">
                <span class="assistant-name">{{ $r['name'] }}</span>
                <span class="assistant-code">{{ $r['code'] }}</span>
              </td>
              <td class="col-shift">
                <span class="shift-main">{{ $r['shift'] }}</span>
                @if (!empty($r['lunch']))
                  <span class="shift-lunch">Almoço: {{ $r['lunch'] }}</span>
                @elseif (!$r['is_absent'])
                  <span class="shift-lunch">Sem pausa almoço</span>
                @endif
              </td>
              <td class="col-timeline">
                <div class="tl-row-wrap">
                  <!-- Vertical time grid lines -->
                  @foreach ($day['quarter_ticks'] as $tIdx => $qt)
                    @if ($tIdx > 0 && $tIdx < count($day['quarter_ticks']) - 1)
                      <div class="tl-tick-line {{ $qt['is_hour'] ? 'tl-tick-hour' : ($qt['is_half'] ? 'tl-tick-half' : 'tl-tick-quarter') }}" style="left: {{ $qt['left'] }}%;"></div>
                    @endif
                  @endforeach

                  <!-- Positioned Activity Blocks -->
                  @foreach ($r['timeline_blocks'] as $tb)
                    @if ($tb['type'] === 'absent')
                      <div class="tl-block tl-block-absent" style="left: {{ $tb['left'] }}%; width: {{ $tb['width'] }}%;">
                        <div class="tl-inner-absent">AUSENTE</div>
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
    @endif

    @if ($dayIdx < count($days) - 1)
      <div class="page-break"></div>
    @endif
  @endforeach

</body>
</html>