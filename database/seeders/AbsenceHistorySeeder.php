<?php

namespace Database\Seeders;

use App\Models\Absences\Absence;
use App\Models\Absences\AbsenceType;
use App\Models\Assistants\Assistant;
use App\Models\Auth\User;
use App\Services\Assistants\AssistantDocumentStorageService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Storage;

class AbsenceHistorySeeder extends Seeder
{
    /**
     * Semeia faltas históricas realistas em dias úteis desde setembro de 2025 até final de novembro de 2026.
     * NENHUMA falta é semeada a partir de 2 de dezembro de 2026 (nem em feriados como 1 de dezembro),
     * permitindo que a falta de 7 de dezembro seja adicionada ao vivo pela equipa.
     */
    public function run(): void
    {
        $admin = User::where('email', 'sgde@admin.com')->first();
        $adminId = $admin?->id;

        $types = AbsenceType::all()->keyBy('name');
        $assistants = Assistant::with('user')->get()->keyBy('internal_number');

        if ($types->isEmpty() || $assistants->isEmpty()) {
            return;
        }

        $storageService = app(AssistantDocumentStorageService::class);
        $disk = $storageService->getDisk('absences_files');
        $canWriteLocalPdf = in_array($disk, ['public', 'local'], true);

        $records = [
            // Ano Letivo 2025/2026
            [
                'assistant' => 'ME-2001', // Beatriz Almeida
                'type' => 'Consulta ou exame médico',
                'from' => '2025-10-14',
                'until' => '2025-10-14',
                'days' => 1,
                'status' => 'justified',
                'doc' => 'declaracao_presenca_hospital.pdf',
            ],
            [
                'assistant' => 'ME-2002', // Cláudia Teixeira
                'type' => 'Doença',
                'from' => '2025-11-10',
                'until' => '2025-11-11',
                'days' => 2,
                'status' => 'justified',
                'doc' => 'baixa_medica_sns.pdf',
            ],
            [
                'assistant' => 'ME-2003', // Inês Monteiro
                'type' => 'Doação de sangue',
                'from' => '2025-12-03',
                'until' => '2025-12-03',
                'days' => 1,
                'status' => 'justified',
                'doc' => 'declaracao_ipst.pdf',
            ],
            [
                'assistant' => 'ME-2004', // Sofia Marques
                'type' => 'Doença',
                'from' => '2026-01-19',
                'until' => '2026-01-23',
                'days' => 5,
                'status' => 'justified',
                'doc' => 'cit_doenca_natural.pdf',
            ],
            [
                'assistant' => 'ME-2005', // Raquel Nogueira
                'type' => 'Assistência a familiar doente',
                'from' => '2026-02-12',
                'until' => '2026-02-13',
                'days' => 2,
                'status' => 'justified',
                'doc' => 'comprovativo_assistencia_filho.pdf',
            ],
            [
                'assistant' => 'ME-2006', // Helena Cardoso
                'type' => 'Formação',
                'from' => '2026-03-09',
                'until' => '2026-03-09',
                'days' => 1,
                'status' => 'justified',
                'doc' => 'certificado_formacao_dgae.pdf',
            ],
            [
                'assistant' => 'ME-3001', // Teresa Lopes
                'type' => 'Consulta ou exame médico',
                'from' => '2026-04-15',
                'until' => '2026-04-15',
                'days' => 1,
                'status' => 'justified',
                'doc' => 'justificacao_consulta_externa.pdf',
            ],
            [
                'assistant' => 'ME-3002', // Mariana Correia
                'type' => 'Doença',
                'from' => '2026-05-18',
                'until' => '2026-05-19',
                'days' => 2,
                'status' => 'justified',
                'doc' => 'atestado_medico.pdf',
            ],
            [
                'assistant' => 'ME-3003', // Catarina Mendes
                'type' => 'Prestação de provas de avaliação',
                'from' => '2026-06-11',
                'until' => '2026-06-11',
                'days' => 1,
                'status' => 'justified',
                'doc' => 'declaracao_exame_ensino_superior.pdf',
            ],
            [
                'assistant' => 'ME-3004', // Lúcia Gonçalves
                'type' => 'Assuntos pessoais',
                'from' => '2026-07-07',
                'until' => '2026-07-07',
                'days' => 1,
                'status' => 'justified',
                'doc' => null,
            ],

            // Ano Letivo 2026/2027 (Setembro até 30 de Novembro de 2026 — sem feriados nem faltas futuras)
            [
                'assistant' => 'ME-2001', // Beatriz Almeida
                'type' => 'Doação de sangue',
                'from' => '2026-09-22',
                'until' => '2026-09-22',
                'days' => 1,
                'status' => 'justified',
                'doc' => 'comprovativo_dadiva_ipst.pdf',
            ],
            [
                'assistant' => 'ME-2003', // Inês Monteiro
                'type' => 'Doença',
                'from' => '2026-10-14',
                'until' => '2026-10-16',
                'days' => 3,
                'status' => 'justified',
                'doc' => 'baixa_medica_out2026.pdf',
            ],
            [
                'assistant' => 'ME-2004', // Sofia Marques
                'type' => 'Consulta ou exame médico',
                'from' => '2026-10-28',
                'until' => '2026-10-28',
                'days' => 1,
                'status' => 'justified',
                'doc' => 'declaracao_consulta_hospitalar.pdf',
            ],
            [
                'assistant' => 'ME-2005', // Raquel Nogueira
                'type' => 'Assistência a familiar doente',
                'from' => '2026-11-09',
                'until' => '2026-11-10',
                'days' => 2,
                'status' => 'justified',
                'doc' => 'declaracao_assistencia_familiar.pdf',
            ],
            [
                'assistant' => 'ME-3002', // Mariana Correia
                'type' => 'Formação',
                'from' => '2026-11-18',
                'until' => '2026-11-18',
                'days' => 1,
                'status' => 'justified',
                'doc' => 'comprovativo_formacao_primeiros_socorros.pdf',
            ],
            [
                'assistant' => 'ME-3001', // Teresa Lopes
                'type' => 'Consulta ou exame médico',
                'from' => '2026-11-25',
                'until' => '2026-11-25',
                'days' => 1,
                'status' => 'justified',
                'doc' => 'declaracao_presenca_clinica.pdf',
            ],
            [
                'assistant' => 'ME-2002', // Cláudia Teixeira (sexta-feira, dia útil)
                'type' => 'Consulta ou exame médico',
                'from' => '2026-11-27',
                'until' => '2026-11-27',
                'days' => 1,
                'status' => 'justified',
                'doc' => 'comprovativo_consulta_dentaria.pdf',
            ],
        ];

        foreach ($records as $item) {
            $assistant = $assistants->get($item['assistant']);
            $type = $types->get($item['type']);

            if (! $assistant || ! $type) {
                continue;
            }

            $docPath = null;
            if ($item['doc'] && $assistant->user) {
                $dir = $storageService->getAssistantDirectory(
                    $assistant->user->first_name,
                    $assistant->user->last_name,
                    $assistant->internal_number,
                    'absences_files'
                );
                $docPath = "{$dir}/{$item['doc']}";
                if ($canWriteLocalPdf) {
                    Storage::disk($disk)->put(
                        $docPath,
                        $this->buildMinimalPdf("Comprovativo de Falta ({$item['type']}) - {$assistant->user->first_name} {$assistant->user->last_name} - {$item['from']}")
                    );
                }
            }

            Absence::updateOrCreate(
                [
                    'assistant_id' => $assistant->id,
                    'valid_from' => $item['from'],
                ],
                [
                    'absence_type_id' => $type->id,
                    'valid_until' => $item['until'],
                    'total_days' => $item['days'],
                    'status' => $item['status'],
                    'document_name' => $item['doc'],
                    'document_path' => $docPath,
                    'created_by_user_id' => $adminId,
                    'justified_by_user_id' => $item['status'] === 'justified' ? $adminId : null,
                    'justification' => 'Comprovativo validado pelos serviços administrativos da escola.',
                ]
            );
        }
    }

    private function buildMinimalPdf(string $text): string
    {
        $safe = preg_replace('/[^\x20-\x7E]/', '', iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $text) ?: $text);
        $safe = str_replace(['\\', '(', ')'], ['\\\\', '\\(', '\\)'], (string) $safe);
        $stream = "BT /F1 12 Tf 50 750 Td ({$safe}) Tj ET";
        $len = strlen($stream);

        return "%PDF-1.4\n"
            ."1 0 obj << /Type /Catalog /Pages 2 0 R >> endobj\n"
            ."2 0 obj << /Type /Pages /Kids [3 0 R] /Count 1 >> endobj\n"
            ."3 0 obj << /Type /Page /Parent 2 0 R /MediaBox [0 0 595 842] /Contents 4 0 R /Resources << /Font << /F1 5 0 R >> >> >> endobj\n"
            ."4 0 obj << /Length {$len} >> stream\n{$stream}\nendstream endobj\n"
            ."5 0 obj << /Type /Font /Subtype /Type1 /BaseFont /Helvetica >> endobj\n"
            ."xref\n0 6\n0000000000 65535 f \n0000000009 00000 n \n0000000058 00000 n \n0000000115 00000 n \n0000000241 00000 n \n0000000390 00000 n \n"
            ."trailer << /Size 6 /Root 1 0 R >>\nstartxref\n460\n%%EOF\n";
    }
}
