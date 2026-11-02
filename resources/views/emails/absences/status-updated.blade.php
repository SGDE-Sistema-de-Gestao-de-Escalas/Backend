<x-mail::message>
# Atualização de Estado de Falta

O estado da falta / ausência associada a **{{ $user->first_name }} {{ $user->last_name }}** foi analisado e atualizado pela administração escolar para **{{ $statusLabel }}**.

<x-mail::panel>
**Estabelecimento de Ensino:** {{ $schoolName }}<br>
**Motivo / Tipo de Falta:** {{ $absenceTypeName }}<br>
**Data / Período:** {{ $periodLabel }}<br>
**Decisão:** {{ $statusLabel }}
@if ($notes)
<br>**Observações da Administração:** {{ $notes }}
@endif
</x-mail::panel>

<x-mail::button :url="$absencesUrl" :color="$isJustified ? 'primary' : 'error'">
Ver Detalhes na Plataforma
</x-mail::button>

<div class="link-fallback-label">
Hiperligação direta de acesso:
</div>

<div class="link-fallback-box">
<a href="{{ $absencesUrl }}" target="_blank" rel="noopener">{{ $absencesUrl }}</a>
</div>

<p class="help-note">
O histórico completo de faltas e justificações pode ser consultado na plataforma SGDE.
</p>
</x-mail::message>