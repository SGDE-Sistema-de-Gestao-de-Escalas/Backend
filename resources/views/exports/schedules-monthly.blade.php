<!DOCTYPE html>
<html lang="pt">
<head>
<meta charset="UTF-8">
<title>Horário Mensal de Serviço — {{ $school->name }}</title>
<link rel="stylesheet" href="{{ resource_path('css/exports/schedules-monthly.css') }}">
</head>
<body>

  <div class="footer">
    <table class="footer-table">
      <tr>
        <td class="footer-left">Impresso em {{ $printedAt }}</td>
        <td class="footer-right">{{ $appVersion ?? config('app.version') }}</td>
      </tr>
    </table>
  </div>

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
          <div class="school-title">{{ $school->name }} — Horário Mensal de Serviço</div>
          <div class="school-subtitle">
            <span class="assistant-badge">{{ $sheet['assistant_name'] }}{{ !empty($sheet['assistant_code']) ? ' (' . $sheet['assistant_code'] . ')' : '' }}</span>
            · Assistente Operacional{{ $school->address ? ' · ' . $school->address : '' }}
          </div>
        </td>
        <td class="header-period-cell">
          <div class="date-title">{{ $monthYearTitle }}</div>
          <div class="hours-subtitle">{{ $sheet['period_subtitle'] }}</div>
        </td>
      </tr>
    </table>

    <div class="legend-bar">
      <span class="legend-label">Legenda:</span>
      <span class="legend-item">
        <span class="legend-swatch legend-swatch-working"></span>Turno de Serviço (Entrada – Saída)
      </span>
      <span class="legend-item">
        <span class="legend-swatch legend-swatch-lunch"></span>Pausa de Almoço
      </span>
      <span class="legend-item">
        <span class="legend-swatch legend-swatch-absent"></span>Falta / Ausência Programada
      </span>
      @if (!empty($sheet['has_unpublished']))
        <span class="legend-item">
          <span class="legend-swatch legend-swatch-unpublished"></span>Sem Horário Publicado
        </span>
      @endif
      <span class="legend-item">
        <span class="legend-swatch legend-swatch-off"></span>Folga Semanal / Feriado / Encerrado
      </span>
    </div>

    <table class="calendar-table {{ ($cardHeightPt ?? 51) <= 39 ? 'calendar-compact' : '' }}">
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
                    <td class="day-header-left">
                      <span class="{{ ($cell['state'] === 'weekend' || $cell['state'] === 'holiday') ? 'day-num-off' : (!$cell['in_month'] ? 'day-num-adj' : 'day-num') }}">
                        {{ $cell['day_num'] }}
                      </span>
                    </td>
                    <td class="day-header-right">
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
