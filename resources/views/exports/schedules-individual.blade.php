<!DOCTYPE html>
<html lang="pt">
<head>
<meta charset="UTF-8">
<title>Horário Individual de Serviço — {{ $school->name }}</title>
<link rel="stylesheet" href="{{ resource_path('css/exports/schedules-individual.css') }}">
</head>
<body>

  <!-- FOOTER -->
  <div class="footer">
    <table class="footer-table">
      <tr>
        <td class="footer-left">Impresso em {{ $printedAt }}</td>
        <td class="footer-right">{{ $appVersion }}</td>
      </tr>
    </table>
  </div>

  @if (empty($sheets))
    <table class="header-table">
      <tr>
        <td class="header-logo-cell">
          @if (!empty($logoBase64))
            <img src="{{ $logoBase64 }}" class="header-logo-img" />
          @else
            <strong class="header-logo-fallback">SGDE</strong>
          @endif
        </td>
        <td>
          <div class="school-title">{{ $school->name }} — Horário Individual de Serviço</div>
          <div class="school-subtitle">Assistentes Operacionais{{ $school->address ? ' · ' . $school->address : '' }}</div>
        </td>
        <td class="header-period-cell">
          <div class="date-title">{{ $periodLabel }}</div>
          <div class="hours-subtitle">Funcionamento: {{ $openTime }} – {{ $closeTime }}</div>
        </td>
      </tr>
    </table>
    <div class="closed-box">
      Não existe nenhuma escala publicada para os parâmetros selecionados.
    </div>
  @else
    @php
      $tableDensityClass = ($rowHeightPt ?? 76) <= 56
        ? 'schedule-table-compact'
        : (($rowHeightPt ?? 76) <= 64 ? 'schedule-table-medium' : '');
    @endphp
    @foreach ($sheets as $sheetIdx => $sheet)
      <table class="header-table">
        <tr>
          <td class="header-logo-cell">
            @if (!empty($logoBase64))
              <img src="{{ $logoBase64 }}" class="header-logo-img" />
            @else
              <strong class="header-logo-fallback">SGDE</strong>
            @endif
          </td>
          <td>
            <div class="school-title">{{ $school->name }} — Horário Individual de Serviço</div>
            <div class="school-subtitle">
              <span class="assistant-badge">{{ $sheet['assistant_name'] }}{{ $sheet['assistant_code'] ? ' (' . $sheet['assistant_code'] . ')' : '' }}</span>
              · Assistente Operacional{{ $school->address ? ' · ' . $school->address : '' }}
            </div>
          </td>
          <td class="header-period-cell">
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
            <span class="legend-swatch" style="background-color: {{ $act['bg'] }}; border-color: {{ $act['border'] }}; border-top-color: {{ $act['color'] }};"></span>{{ $act['name'] }}
          </span>
        @endforeach
        <span class="legend-item">
          <span class="legend-swatch legend-swatch-lunch"></span>Almoço
        </span>
        <span class="legend-item">
          <span class="legend-swatch legend-swatch-dayoff"></span>Folga / Encerrado
        </span>
        @if (!empty($sheet['has_unpublished']))
          <span class="legend-item">
            <span class="legend-swatch legend-swatch-unpublished"></span>Sem Horário Publicado
          </span>
        @endif
        <span class="legend-item">
          <span class="legend-swatch legend-swatch-absent"></span>Ausente
        </span>
      </div>

      <!-- TABELA COM TIMELINE FIXA SEMANAL -->
      <table class="schedule-table {{ $tableDensityClass }}">
        <thead>
          <tr>
            <th class="col-assistant">Dia / Data</th>
            <th class="col-shift">Turno / Almoço</th>
            <th class="col-timeline">
              <div class="tl-header-wrap">
                @foreach ($timeHeaders as $idx => $th)
                  <div class="tl-hour-header {{ $idx === 0 ? 'tl-hour-header-first' : '' }}" style="left: {{ $th['left'] }}%; width: {{ $th['width'] }}%;">
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
