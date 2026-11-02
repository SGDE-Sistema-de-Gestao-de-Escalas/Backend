<x-mail::message>
# Bem-vindo ao SGDE

A conta de **{{ $user->first_name }} {{ $user->last_name }}** com o perfil de **{{ $roleName }}** foi criada com sucesso no sistema pelo administrador.

Para aceder à plataforma e aos seus horários, é necessário ativar a sua conta e definir a sua palavra-passe de acesso através do botão abaixo:

<x-mail::button :url="$setupUrl" color="primary">
Ativar Conta e Definir Palavra-passe
</x-mail::button>

<div class="link-fallback-label">
Hiperligação direta para ativação da conta:
</div>

<div class="link-fallback-box">
<a href="{{ $setupUrl }}" target="_blank" rel="noopener">{{ $setupUrl }}</a>
</div>

<p class="help-note">
Esta ligação é válida por 60 minutos por motivos de segurança. Caso expire, solicite um novo envio junto da administração escolar.
</p>
</x-mail::message>