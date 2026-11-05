<!DOCTYPE html>
<html lang="pt">
<head>
<meta charset="UTF-8">
<title>Ficha de Dados Pessoais (RGPD) — {{ $fullName }}</title>
<style>
  @page {
    size: A4 portrait;
    margin: 16mm 16mm 18mm 16mm;
  }
  body {
    font-family: DejaVu Sans, Helvetica, Arial, sans-serif;
    font-size: 9pt;
    color: #0f172a;
    margin: 0;
    padding: 0;
    line-height: 1.45;
  }

  /* Header */
  .header-table {
    width: 100%;
    border-collapse: collapse;
    border-bottom: 1.5pt solid #0f172a;
    margin-bottom: 14pt;
  }
  .header-table td {
    vertical-align: bottom;
    padding: 0 0 8pt 0;
  }
  .doc-title {
    font-size: 13pt;
    font-weight: bold;
    color: #0f172a;
    margin: 0;
  }
  .doc-subtitle {
    font-size: 7.5pt;
    color: #475569;
    margin: 2pt 0 0 0;
  }
  .meta-right {
    text-align: right;
    font-size: 7.5pt;
    color: #64748b;
    white-space: nowrap;
  }

  /* Intro Banner */
  .intro-box {
    background-color: #f8fafc;
    border: 0.5pt solid #cbd5e1;
    border-left: 3pt solid #0e7c59;
    border-radius: 3pt;
    padding: 8pt 10pt;
    margin-bottom: 14pt;
    font-size: 8pt;
    color: #334155;
  }

  /* Section */
  .section-title {
    font-size: 9.5pt;
    font-weight: bold;
    text-transform: uppercase;
    letter-spacing: 0.4pt;
    color: #0e7c59;
    border-bottom: 0.75pt solid #cbd5e1;
    padding-bottom: 3pt;
    margin: 14pt 0 7pt 0;
  }

  .data-table {
    width: 100%;
    border-collapse: collapse;
    margin-bottom: 6pt;
  }
  .data-table th,
  .data-table td {
    padding: 5.5pt 8pt;
    border: 0.5pt solid #e2e8f0;
    font-size: 8.5pt;
    text-align: left;
    vertical-align: top;
  }
  .data-table th {
    width: 36%;
    background-color: #f8fafc;
    color: #475569;
    font-weight: bold;
  }
  .data-table td {
    color: #0f172a;
  }

  .badge-active {
    display: inline-block;
    padding: 1pt 5pt;
    border-radius: 2pt;
    font-size: 7.5pt;
    font-weight: bold;
    background-color: #dcfce7;
    color: #166534;
  }
  .badge-inactive {
    display: inline-block;
    padding: 1pt 5pt;
    border-radius: 2pt;
    font-size: 7.5pt;
    font-weight: bold;
    background-color: #fee2e2;
    color: #991b1b;
  }

  .legal-box {
    margin-top: 18pt;
    padding: 9pt 10pt;
    background-color: #f1f5f9;
    border: 0.5pt solid #cbd5e1;
    border-radius: 3pt;
    font-size: 7.3pt;
    color: #475569;
  }

  /* Footer */
  .footer {
    position: fixed;
    bottom: -6mm;
    left: 0;
    right: 0;
    border-top: 0.5pt solid #cbd5e1;
    padding-top: 4pt;
    font-size: 6.5pt;
    color: #64748b;
  }
  .footer-table {
    width: 100%;
    border-collapse: collapse;
  }
</style>
</head>
<body>

  <div class="footer">
    <table class="footer-table">
      <tr>
        <td style="text-align: left;">SGDE — Sistema de Gestão de Escalas · Documento de Portabilidade e Acesso (RGPD)</td>
        <td style="text-align: right;">Emitido em {{ $exportedAtFormatted }}</td>
      </tr>
    </table>
  </div>

  <table class="header-table">
    <tr>
      <td style="width: 95pt;">
        @if (!empty($logoBase64))
          <img src="{{ $logoBase64 }}" style="height: 24pt; width: auto;" />
        @else
          <strong style="font-size: 13pt; color: #0e7c59;">SGDE</strong>
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

