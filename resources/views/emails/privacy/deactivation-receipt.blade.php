<x-mail::message>
# Comprovativo de Pedido de Anonimização (RGPD)

Olá **{{ $user->first_name }}**,

Confirmamos que o seu pedido formal de anonimização de dados pessoais e desativação de conta ao abrigo do Regulamento Geral sobre a Proteção de Dados (RGPD) foi registado com sucesso no sistema **SGDE**.

Este documento digital serve como comprovativo formal do seu requerimento junto da direção e administração escolar do seu agrupamento.

<x-mail::panel>
**Nome do Requerente:** {{ $user->first_name }} {{ $user->last_name }}<br>
**Email:** {{ $user->email }}<br>
@if ($user->assistant?->internal_number)
**N.º Mecanográfico:** {{ $user->assistant->internal_number }}<br>
@endif
**Estabelecimento de Ensino:** {{ $schoolName }}<br>
**Data e Hora do Pedido:** {{ now()->format('d/m/Y \à\s H:i') }}
@if ($reason)
<br>**Motivo Apresentado:** {{ $reason }}
@endif
</x-mail::panel>

<h3 style="font-size: 15px; font-weight: 700; color: #111827; margin-top: 24px; margin-bottom: 12px; text-align: left;">
Próximos Passos do Processo
</h3>

<table class="steps-table" width="100%" cellpadding="0" cellspacing="0" role="presentation" style="margin: 14px 0 22px 0; border: 1px solid #E2E8F0; border-radius: 12px; background-color: #F8FAFC; overflow: hidden; border-collapse: separate;">
<tr>
<td style="padding: 14px 18px; border-bottom: 1px solid #E2E8F0; background-color: #F8FAFC;">
<table cellpadding="0" cellspacing="0" role="presentation" width="100%">
<tr>
<td valign="top" style="width: 32px; padding-right: 14px;">
<div style="width: 26px; height: 26px; border-radius: 50%; background-color: #00B884; color: #FFFFFF; font-weight: 700; font-size: 12px; line-height: 26px; text-align: center;">1</div>
</td>
<td valign="top" style="text-align: left;">
<div style="font-weight: 700; color: #111827; font-size: 14px; margin-bottom: 4px; text-align: left;">Notificação Institucional</div>
<div style="font-size: 13.5px; line-height: 1.6; color: #4B5563; text-align: justify; text-justify: inter-word;">Os administradores da sua instituição foram alertados de imediato na plataforma e receberam os dados do seu pedido por email.</div>
</td>
</tr>
</table>
</td>
</tr>
<tr>
<td style="padding: 14px 18px; border-bottom: 1px solid #E2E8F0; background-color: #F8FAFC;">
<table cellpadding="0" cellspacing="0" role="presentation" width="100%">
<tr>
<td valign="top" style="width: 32px; padding-right: 14px;">
<div style="width: 26px; height: 26px; border-radius: 50%; background-color: #00B884; color: #FFFFFF; font-weight: 700; font-size: 12px; line-height: 26px; text-align: center;">2</div>
</td>
<td valign="top" style="text-align: left;">
<div style="font-weight: 700; color: #111827; font-size: 14px; margin-bottom: 4px; text-align: left;">Validação e Anonimização</div>
<div style="font-size: 13.5px; line-height: 1.6; color: #4B5563; text-align: justify; text-justify: inter-word;">A administração procederá à anonimização irreversível dos seus dados cadastrais (nome, email, NIF, contactos), preservando unicamente o histórico legal de escalas e presenças exigido por lei.</div>
</td>
</tr>
</table>
</td>
</tr>
<tr>
<td style="padding: 14px 18px; background-color: #F8FAFC;">
<table cellpadding="0" cellspacing="0" role="presentation" width="100%">
<tr>
<td valign="top" style="width: 32px; padding-right: 14px;">
<div style="width: 26px; height: 26px; border-radius: 50%; background-color: #00B884; color: #FFFFFF; font-weight: 700; font-size: 12px; line-height: 26px; text-align: center;">3</div>
</td>
<td valign="top" style="text-align: left;">
<div style="font-weight: 700; color: #111827; font-size: 14px; margin-bottom: 4px; text-align: left;">Conclusão do Processo</div>
<div style="font-size: 13.5px; line-height: 1.6; color: #4B5563; text-align: justify; text-justify: inter-word;">Uma vez efetivada a anonimização, a sua conta de acesso é revogada em definitivo e os dados pessoais deixam de existir no sistema.</div>
</td>
</tr>
</table>
</td>
</tr>
</table>

Se não submeteu este pedido ou se necessitar de esclarecimentos adicionais, por favor contacte com a máxima brevidade a secretaria ou administração do seu agrupamento escolar.
</x-mail::message>
