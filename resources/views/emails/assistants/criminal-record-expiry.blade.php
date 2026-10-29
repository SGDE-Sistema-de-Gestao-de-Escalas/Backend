<x-mail::message>
# {{ $daysRemaining <= 0 ? 'Alerta Crítico: Registo Criminal Expirado' : 'Aviso de Validade de Registo Criminal' }}

@if ($daysRemaining <= 0)
O certificado de registo criminal de **{{ $assistantLabel }}** atingiu hoje a sua **data limite de validade** e encontra-se expirado.
@else
O certificado de registo criminal de **{{ $assistantLabel }}** irá expirar dentro de **{{ $daysRemaining }} dias**.
@endif

<x-mail::panel>
**Assistente:** {{ $assistantLabel }}<br>
**Email:** {{ $assistantEmail }}<br>
**Estabelecimento de Ensino:** {{ $schoolName }}<br>
**Data de Validade:** {{ $expiryFormatted }}<br>
**Estado do Certificado:** {{ $statusLabel }}
</x-mail::panel>

<x-mail::button :url="$assistantsAdminUrl" :color="$isUrgent ? 'error' : 'primary'">
Consultar Ficha do Assistente
</x-mail::button>

<div class="link-fallback-label">
Hiperligação direta de acesso:
</div>

<div class="link-fallback-box">
<a href="{{ $assistantsAdminUrl }}" target="_blank" rel="noopener">{{ $assistantsAdminUrl }}</a>
</div>

<p class="help-note">
Solicite a renovação do documento comprovativo e proceda à atualização da ficha do assistente na plataforma SGDE.
</p>
</x-mail::message>

