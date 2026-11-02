<x-mail::message>
# Ativação de Conta

A conta de **{{ $user->first_name }} {{ $user->last_name }}** com o perfil de **{{ $roleName }}** foi registada com sucesso no sistema pelo administrador.

Para aceder à plataforma, é necessário confirmar a conta e definir uma palavra-passe definitiva através do botão abaixo:

<x-mail::button :url="$setupUrl" color="primary">
Confirmar e Ativar Conta
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