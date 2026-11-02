<x-mail::message>
# {{ $isUpdate ? 'Atualização de Escala' : 'Publicação de Escala' }}

@if ($isUpdate)
A escala de serviço atribuída a **{{ $user->first_name }} {{ $user->last_name }}** sofreu alterações e foi **atualizada** pela administração escolar.
@else
Encontra-se **publicada** uma nova escala de serviço atribuída a **{{ $user->first_name }} {{ $user->last_name }}**.
@endif

<x-mail::panel>
**Estabelecimento de Ensino:** {{ $schoolName }}<br>
**{{ $isSingleDay ? 'Data Publicada:' : 'Período Publicado:' }}** {{ $periodLabel }}<br>
**Estado:** Publicada
</x-mail::panel>

<x-mail::button :url="$scheduleUrl" color="primary">
Consultar Escala de Serviço
</x-mail::button>

<div class="link-fallback-label">
Hiperligação direta de acesso:
</div>

<div class="link-fallback-box">
<a href="{{ $scheduleUrl }}" target="_blank" rel="noopener">{{ $scheduleUrl }}</a>
</div>

<p class="help-note">
O detalhe completo dos turnos, horários e atividades pode ser consultado no portal institucional.
</p>
</x-mail::message>