<x-mail::message>
# Pedido de Desativação e Esquecimento (RGPD)

O(A) utilizador(a) **{{ $user->first_name }} {{ $user->last_name }}** submeteu um novo pedido de desativação / direito ao esquecimento através do portal **SGDE**.

<x-mail::panel>
**Nome:** {{ $user->first_name }} {{ $user->last_name }}<br>
**Email:** {{ $user->email }}<br>
**Perfil:** {{ $user->role?->name ?? 'Utilizador' }}<br>
**Escola / Estabelecimento:** {{ $schoolName }}
@if ($reason)
<br>**Motivo Apresentado:** {{ $reason }}
@endif
</x-mail::panel>

@php
$adminUrl = rtrim((string) config('app.frontend_url', config('app.url')), '/') . '/assistants';
@endphp

<x-mail::button :url="$adminUrl" color="primary">
Analisar Pedido na Plataforma
</x-mail::button>

<div class="link-fallback-label">
Hiperligação direta de acesso:
</div>

<div class="link-fallback-box">
<a href="{{ $adminUrl }}" target="_blank" rel="noopener">{{ $adminUrl }}</a>
</div>

<p class="help-note">
Proceda à análise do pedido e posterior desativação ou anonimização da conta em conformidade com o Regulamento Geral sobre a Proteção de Dados (RGPD).
</p>
</x-mail::message>