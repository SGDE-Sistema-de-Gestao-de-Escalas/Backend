<!DOCTYPE html>
<html lang="pt">
<head>
<meta charset="UTF-8">
<title>Escala de Serviço — {{ $school->name }}</title>
<link rel="stylesheet" href="{{ resource_path('css/exports/schedules-wall.css') }}">
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

  @foreach ($days as $dayIdx => $day)
    <!-- HEADER -->
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
          <div class="school-title">{{ $school->name }} — {{ $docTitle }}</div>
          <div class="school-subtitle">Assistentes Operacionais{{ $school->address ? ' · ' . $school->address : '' }}</div>
        </td>
        <td class="header-period-cell">
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
            <span class="legend-swatch" style="background-color: {{ $act['bg'] }}; border-color: {{ $act['border'] }}; border-top-color: {{ $act['color'] }};"></span>{{ $act['name'] }}
          </span>
        @endforeach
        <span class="legend-item">
          <span class="legend-swatch legend-swatch-lunch"></span>Almoço
        </span>
        <span class="legend-item">
          <span class="legend-swatch legend-swatch-absent"></span>Ausente
        </span>
      </div>

      <!-- TABELA COM TIMELINE FIXA -->
      <table class="schedule-table">
        <thead>
          <tr>
            <th class="col-assistant">Assistente</th>
            <th class="col-shift">Turno / Almoço</th>
            <th class="col-timeline">
              <div class="tl-header-wrap">
                @foreach ($day['time_headers'] as $idx => $th)
                  <div class="tl-hour-header {{ $idx === 0 ? 'tl-hour-header-first' : '' }}" style="left: {{ $th['left'] }}%; width: {{ $th['width'] }}%;">
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