<x-mail::message>
# Bem-vindo(a) ao SGDE!

Olá **{{ $user->first_name }} {{ $user->last_name }}**,

Damos-lhe as boas-vindas ao **SGDE (Sistema de Gestão de Escalas)**. A sua área pessoal já se encontra disponível para que possa consultar os seus horários de serviço, turnos atribuídos e submeter justificações de ausências.

<x-mail::panel>
@if ($schoolName)
**Estabelecimento de Ensino:** {{ $schoolName }}<br>
@endif
@if ($internalNumber)
**Número Mecanográfico:** {{ $internalNumber }}<br>
@endif
**Email de Acesso:** {{ $user->email }}
</x-mail::panel>

Para concluir a ativação da sua conta e fazer o seu primeiro acesso, crie a sua palavra-passe pessoal clicando no botão abaixo:

<x-mail::button :url="$setupUrl" color="primary">
Criar a Minha Palavra-passe
</x-mail::button>

<div class="link-fallback-label">
Se o botão não funcionar, copie e cole a seguinte ligação no seu navegador:
</div>

<div class="link-fallback-box">
<a href="{{ $setupUrl }}" target="_blank" rel="noopener">{{ $setupUrl }}</a>
</div>

<p class="help-note">
Por motivos de segurança, esta ligação é válida durante 60 minutos. Caso expire antes de concluir o acesso, poderá pedir um novo link em "Esqueci-me da palavra-passe" na página de login.
</p>
</x-mail::message>