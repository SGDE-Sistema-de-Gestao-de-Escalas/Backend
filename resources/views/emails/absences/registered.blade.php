<x-mail::message>
# Falta Registada

Foi registada uma nova **falta / ausência** associada ao perfil de **{{ $user->first_name }} {{ $user->last_name }}** na administração escolar.

<x-mail::panel>
**Estabelecimento de Ensino:** {{ $schoolName }}<br>
**Motivo / Tipo de Falta:** {{ $absenceTypeName }}<br>
**Data / Período:** {{ $periodLabel }}<br>
**Estado Atual:** {{ $statusLabel }}
@if ($notes)
<br>**Observações:** {{ $notes }}
@endif
</x-mail::panel>

@if ($status === 'pending' || $requiresDocument)
Caso exista documento comprovativo para justificar esta ausência, a submissão deve ser efetuada diretamente na área pessoal da plataforma.
@endif

<x-mail::button :url="$absencesUrl" color="primary">
Consultar Faltas na Plataforma
</x-mail::button>

<div class="link-fallback-label">
Hiperligação direta de acesso:
</div>

<div class="link-fallback-box">
<a href="{{ $absencesUrl }}" target="_blank" rel="noopener">{{ $absencesUrl }}</a>
</div>

<p class="help-note">
O histórico completo de ausências e justificações encontra-se disponível para consulta na área pessoal.
</p>
</x-mail::message>