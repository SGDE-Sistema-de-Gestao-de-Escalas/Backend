<x-mail::message>
# Pedido de Desativação e Esquecimento (RGPD)

Foi submetido um novo pedido de desativação / direito ao esquecimento através do portal SGDE.

### Detalhes do Utilizador:
- **Nome:** {{ $user->first_name }} {{ $user->last_name }}
- **Email:** {{ $user->email }}
- **Perfil:** {{ $user->role?->name ?? 'Utilizador' }}
- **Escola / Estabelecimento:** {{ $schoolName }}

@if ($reason)
### Motivo Apresentado:
> {{ $reason }}
@endif

---

Por favor, aceda à plataforma para proceder à análise do pedido e posterior desativação ou anonimização da conta, garantindo o cumprimento do Regulamento Geral sobre a Proteção de Dados (RGPD).

Obrigado,<br>
Sistema de Gestão de Escalas — {{ config('app.name') }}
</x-mail::message>

