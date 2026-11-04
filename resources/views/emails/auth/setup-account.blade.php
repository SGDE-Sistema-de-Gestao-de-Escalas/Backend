<x-mail::message>
# Bem-vindo ao SGDE

A conta de **{{ $user->first_name }} {{ $user->last_name }}** com o perfil de **{{ $roleName }}** foi criada com sucesso no sistema **SGDE**.

Para aceder à plataforma e consultar os seus horários de serviço, é necessário criar a sua palavra-passe de acesso através do botão abaixo:

<x-mail::button :url="$setupUrl" color="primary">
Criar Palavra-passe
</x-mail::button>

<div class="link-fallback-label">
Hiperligação direta para criar a sua palavra-passe:
</div>

<div class="link-fallback-box">
<a href="{{ $setupUrl }}" target="_blank" rel="noopener">{{ $setupUrl }}</a>
</div>

<p class="help-note">
Esta ligação é válida por 60 minutos por motivos de segurança. Caso expire, contacte a administração escolar ou utilize a recuperação de acesso.
</p>
</x-mail::message>