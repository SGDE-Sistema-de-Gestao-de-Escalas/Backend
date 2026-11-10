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

@include('emails.partials.fallback-link', ['url' => $scheduleUrl])

<p class="help-note">
O detalhe completo dos turnos, horários e atividades pode ser consultado no portal institucional.
</p>
</x-mail::message>