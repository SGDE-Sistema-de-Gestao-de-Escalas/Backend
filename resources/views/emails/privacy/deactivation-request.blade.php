<x-mail::message>
# Pedido de Anonimização e Esquecimento (RGPD)

O(A) assistente **{{ $user->first_name }} {{ $user->last_name }}** submeteu um pedido formal de anonimização de dados pessoais e desativação de conta através da plataforma **SGDE**.

<x-mail::panel>
**Nome do(a) Assistente:** {{ $user->first_name }} {{ $user->last_name }}<br>
**Email:** {{ $user->email }}<br>
@if ($user->assistant?->internal_number)
**N.º Mecanográfico:** {{ $user->assistant->internal_number }}<br>
@endif
**Perfil:** {{ $user->role?->name ?? 'Assistente Operacional' }}<br>
**Escola / Estabelecimento:** {{ $schoolName }}
@if ($reason)
<br>**Motivo Apresentado:** {{ $reason }}
@endif
</x-mail::panel>

@php
$assistantId = $user->assistant?->id;
$adminUrl = rtrim((string) config('app.frontend_url'), '/') . ($assistantId ? "/profile/{$assistantId}" : '/assistants');
@endphp

<x-mail::button :url="$adminUrl" color="primary">
Ver Perfil do Assistente na Plataforma
</x-mail::button>

@include('emails.partials.fallback-link', ['url' => $adminUrl])

<p class="help-note">
Pode analisar o perfil, histórico e proceder à anonimização da ficha do assistente diretamente na plataforma, salvaguardando o cumprimento do Regulamento Geral sobre a Proteção de Dados (RGPD).
</p>
</x-mail::message>
