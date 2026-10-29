<x-mail::message>
# Nova Justificação de Falta Submetida

O(A) assistente operacional **{{ $assistantLabel }}** atualizou a sua falta pendente e submeteu uma justificação e/ou documento comprovativo para validação.

<x-mail::panel>
**Assistente:** {{ $assistantLabel }}<br>
**Estabelecimento de Ensino:** {{ $schoolName }}<br>
**Motivo / Tipo de Falta:** {{ $absenceTypeName }}<br>
**Data / Período:** {{ $periodLabel }}<br>
**Comprovativo Anexado:** {{ $documentName ?? 'Sem ficheiro anexado' }}
@if ($justification)
<br>**Justificação Apresentada:** {{ $justification }}
@endif
</x-mail::panel>

<x-mail::button :url="$absencesAdminUrl" color="primary">
Analisar e Validar Falta
</x-mail::button>

<div class="link-fallback-label">
Hiperligação direta de acesso:
</div>

<div class="link-fallback-box">
<a href="{{ $absencesAdminUrl }}" target="_blank" rel="noopener">{{ $absencesAdminUrl }}</a>
</div>

<p class="help-note">
A validação da ausência como Justificada ou Injustificada deve ser efetuada na área de gestão de faltas do portal SGDE.
</p>
</x-mail::message>

