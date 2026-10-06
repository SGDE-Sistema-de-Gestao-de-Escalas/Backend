<x-mail::message>
# Bem-vindo(a) ao SGDE, {{ $user->first_name }} {{ $user->last_name }}

A tua conta de **{{ $roleName }}** foi criada com sucesso pelo administrador do sistema.

Para acederes à plataforma, precisas de configurar a tua palavra-passe definitiva.

<x-mail::button :url="$setupUrl" color="primary">
Configurar Palavra-passe
</x-mail::button>

Este link é válido por 60 minutos. Se expirar, contacta a administração.

Obrigado,<br>
Equipa {{ config('app.name') }}
</x-mail::message>