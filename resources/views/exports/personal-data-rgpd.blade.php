<!DOCTYPE html>
<html lang="pt">
<head>
<meta charset="UTF-8">
<title>Ficha de Dados Pessoais (RGPD) — {{ $fullName }}</title>
<link rel="stylesheet" href="{{ resource_path('css/exports/personal-data-rgpd.css') }}">
</head>
<body>

  <div class="footer">
    <table class="footer-table">
      <tr>
        <td class="footer-left">SGDE — Sistema de Gestão de Escalas · Documento de Portabilidade e Acesso (RGPD)</td>
        <td class="footer-right">Emitido em {{ $exportedAtFormatted }}</td>
      </tr>
    </table>
  </div>

  <table class="header-table">
    <tr>
      <td class="header-logo-cell">
        @if (!empty($logoBase64))
          <img src="{{ $logoBase64 }}" class="header-logo-img" />
        @else
          <strong class="header-logo-fallback">SGDE</strong>
        @endif
      </td>
      <td>
        <div class="doc-title">Ficha de Dados Pessoais (RGPD)</div>
        <div class="doc-subtitle">Direito de Acesso e Portabilidade · Artigos 15.º e 20.º do Regulamento (UE) 2016/679</div>
      </td>
      <td class="meta-right">
        <div><strong>Data de Emissão:</strong> {{ $exportedAtFormatted }}</div>
        <div><strong>Titular:</strong> {{ $fullName }}</div>
      </td>
    </tr>
  </table>

  <div class="intro-box">
    Este documento certifica os dados pessoais e profissionais associados à conta de <strong>{{ $fullName }}</strong> registados na plataforma <strong>SGDE (Sistema de Gestão de Escalas)</strong> à data da sua emissão.
  </div>

  <!-- 1. IDENTIFICAÇÃO E CONTA -->
  <div class="section-title">1. Identificação e Dados da Conta</div>
  <table class="data-table">
    <tr>
      <th>Nome Completo</th>
      <td>{{ $fullName }}</td>
    </tr>
    <tr>
      <th>Endereço de Email (Login)</th>
      <td>{{ $perfil['email'] ?? '—' }}</td>
    </tr>
    <tr>
      <th>Perfil de Acesso na Plataforma</th>
      <td>{{ ($perfil['role'] ?? '') === 'admin' ? 'Administrador / Gestão' : 'Assistente Operacional' }}</td>
    </tr>
    <tr>
      <th>Estado da Conta</th>
      <td>
        @if (!empty($perfil['is_active']))
          <span class="badge-active">Ativa</span>
        @else
          <span class="badge-inactive">Inativa</span>
        @endif
      </td>
    </tr>
    <tr>
      <th>Data de Criação da Conta</th>
      <td>{{ $createdAtFormatted }}</td>
    </tr>
    <tr>
      <th>Última Atualização do Registo</th>
      <td>{{ $updatedAtFormatted }}</td>
    </tr>
  </table>

  @if (!empty($assistente))
    <!-- 2. DADOS PROFISSIONAIS -->
    <div class="section-title">2. Dados Profissionais e Afetação Escolar</div>
    <table class="data-table">
      <tr>
        <th>Número Mecanográfico / Interno</th>
        <td>{{ $assistente['internal_number'] ?: '—' }}</td>
      </tr>
      <tr>
        <th>Estabelecimento de Ensino Base</th>
        <td>{{ $assistente['school'] ?: 'Sem escola atribuída' }}</td>
      </tr>
      <tr>
        <th>Data de Admissão</th>
        <td>{{ $admissionDateFormatted }}</td>
      </tr>
      <tr>
        <th>Disponibilidade para Mobilidade Inter-Escolar</th>
        <td>{{ !empty($assistente['available_for_transfer']) ? 'Sim (Disponível para reforço temporário noutras escolas do agrupamento)' : 'Não (Apenas na escola base)' }}</td>
      </tr>
    </table>

    <!-- 3. CONTACTOS E MORADA -->
    <div class="section-title">3. Contactos Pessoais e Morada</div>
    <table class="data-table">
      <tr>
        <th>Telefone / Telemóvel</th>
        <td>{{ $assistente['phone'] ?: '—' }}</td>
      </tr>
      <tr>
        <th>Data de Nascimento</th>
        <td>{{ $birthDateFormatted }}</td>
      </tr>
      <tr>
        <th>Morada / Arruamento</th>
        <td>{{ $assistente['address_street'] ?: '—' }}</td>
      </tr>
      <tr>
        <th>Código Postal</th>
        <td>{{ $assistente['address_zip_code'] ?: '—' }}</td>
      </tr>
    </table>

    <!-- 4. CONTACTO DE EMERGÊNCIA -->
    <div class="section-title">4. Contacto de Emergência</div>
    <table class="data-table">
      <tr>
        <th>Nome do Contacto</th>
        <td>{{ $assistente['emergency_contact']['name'] ?? '—' }}</td>
      </tr>
      <tr>
        <th>Grau de Parentesco / Relação</th>
        <td>{{ $assistente['emergency_contact']['kinship'] ?? '—' }}</td>
      </tr>
      <tr>
        <th>Telefone de Emergência</th>
        <td>{{ $assistente['emergency_contact']['phone'] ?? '—' }}</td>
      </tr>
    </table>
  @endif

  <div class="legal-box">
    <strong>Nota de Segurança e Proteção de Dados (RGPD):</strong><br />
    Por motivos de segurança e prevenção de exposição indevida (RGPD), as credenciais de acesso e os identificadores fiscais e de segurança social (NIF e NISS) são omitidos desta exportação. O nome e endereço de email podem ser atualizados na área «A Minha Conta», devendo a atualização dos restantes dados pessoais ou profissionais ser solicitada junto da Administração.
  </div>

</body>
</html>

