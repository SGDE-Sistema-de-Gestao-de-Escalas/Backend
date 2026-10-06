<x-mail::message>
# Olá, {{ $user->first_name }} {{ $user->last_name }}   

Recebemos um pedido para repor a palavra-passe da tua conta no SGDE.

Se fizeste este pedido, clica no botão abaixo para definires uma nova palavra-passe:

<x-mail::button :url="$resetUrl" color="primary">
Repor Palavra-passe
</x-mail::button>

Se não solicitaste a reposição da palavra-passe, podes ignorar este email com segurança.

Obrigado,<br>
Equipa {{ config('app.name') }}
</x-mail::message>