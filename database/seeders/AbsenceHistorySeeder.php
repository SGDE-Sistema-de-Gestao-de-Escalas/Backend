<?php

namespace Database\Seeders;

use App\Models\Absences\Absence;
use App\Models\Absences\AbsenceType;
use App\Models\Assistants\Assistant;
use App\Models\Auth\User;
use Illuminate\Database\Seeder;

class AbsenceHistorySeeder extends Seeder
{
    /**
     * Semeia faltas históricas realistas desde setembro de 2025 até 1 de dezembro de 2026.
     * NENHUMA falta é semeada a partir de 2 de dezembro de 2026 para a frente,
     * permitindo que a falta de 7 de dezembro seja adicionada ao vivo pela equipa.
     */
    public function run(): void
    {
        $admin = User::where('email', 'sgde@admin.com')->first();
        $adminId = $admin?->id;

        $types = AbsenceType::all()->keyBy('name');
        $assistants = Assistant::all()->keyBy('internal_number');

        if ($types->isEmpty() || $assistants->isEmpty()) {
            return;
        }

        $records = [
            // Ano Letivo 2025/2026
            [
                'assistant' => 'ME-2001', // Patrícia Neves
                'type' => 'Consulta ou exame médico',
                'from' => '2025-10-14',
                'until' => '2025-10-14',
                'days' => 1,
                'status' => 'justified',
                'doc' => 'declaracao_presenca_hospital.pdf',
            ],
            [
                'assistant' => 'ME-2002', // Anabela Ferreira
                'type' => 'Doença',
                'from' => '2025-11-10',
                'until' => '2025-11-11',
                'days' => 2,
                'status' => 'justified',
                'doc' => 'baixa_medica_sns.pdf',
            ],
            [
                'assistant' => 'ME-2003', // Joana Antunes
                'type' => 'Doação de sangue',
                'from' => '2025-12-03',
                'until' => '2025-12-03',
                'days' => 1,
                'status' => 'justified',
                'doc' => 'declaracao_ipst.pdf',
            ],
            [
                'assistant' => 'ME-2004', // Maria João Bastos
                'type' => 'Doença',
                'from' => '2026-01-19',
                'until' => '2026-01-23',
                'days' => 5,
                'status' => 'justified',
                'doc' => 'cit_doenca_natural.pdf',
            ],
            [
                'assistant' => 'ME-2005', // Marta Rodrigues
                'type' => 'Assistência a familiar doente',
                'from' => '2026-02-12',
                'until' => '2026-02-13',
                'days' => 2,
                'status' => 'justified',
                'doc' => 'comprovativo_assistencia_filho.pdf',
            ],
            [
                'assistant' => 'ME-2006', // Sandra Carvalho
                'type' => 'Formação',
                'from' => '2026-03-09',
                'until' => '2026-03-09',
                'days' => 1,
                'status' => 'justified',
                'doc' => 'certificado_formacao_dgae.pdf',
            ],
            [
                'assistant' => 'ME-3001', // Jacinta Pires
                'type' => 'Consulta ou exame médico',
                'from' => '2026-04-15',
                'until' => '2026-04-15',
                'days' => 1,
                'status' => 'justified',
                'doc' => 'justificacao_consulta_externa.pdf',
            ],
            [
                'assistant' => 'ME-3002', // Carla Moreira
                'type' => 'Doença',
                'from' => '2026-05-18',
                'until' => '2026-05-19',
                'days' => 2,
                'status' => 'justified',
                'doc' => 'atestado_medico.pdf',
            ],
            [
                'assistant' => 'ME-3003', // Filipa Santos
                'type' => 'Prestação de provas de avaliação',
                'from' => '2026-06-11',
                'until' => '2026-06-11',
                'days' => 1,
                'status' => 'justified',
                'doc' => 'declaracao_exame_ensino_superior.pdf',
            ],
            [
                'assistant' => 'ME-3004', // Diana Ribeiro
                'type' => 'Assuntos pessoais',
                'from' => '2026-07-07',
                'until' => '2026-07-07',
                'days' => 1,
                'status' => 'justified',
                'doc' => null,
            ],

            // Ano Letivo 2026/2027 (Setembro até 1 de Dezembro de 2026)
            [
                'assistant' => 'ME-2001', // Patrícia Neves
                'type' => 'Doação de sangue',
                'from' => '2026-09-22',
                'until' => '2026-09-22',
                'days' => 1,
                'status' => 'justified',
                'doc' => 'comprovativo_dadiva_ipst.pdf',
            ],
            [
                'assistant' => 'ME-2003', // Joana Antunes
                'type' => 'Doença',
                'from' => '2026-10-14',
                'until' => '2026-10-16',
                'days' => 3,
                'status' => 'justified',
                'doc' => 'baixa_medica_out2026.pdf',
            ],
            [
                'assistant' => 'ME-2004', // Maria João Bastos
                'type' => 'Consulta ou exame médico',
                'from' => '2026-10-28',
                'until' => '2026-10-28',
                'days' => 1,
                'status' => 'justified',
                'doc' => 'declaracao_consulta_hospitalar.pdf',
            ],
            [
                'assistant' => 'ME-2005', // Marta Rodrigues
                'type' => 'Assistência a familiar doente',
                'from' => '2026-11-09',
                'until' => '2026-11-10',
                'days' => 2,
                'status' => 'justified',
                'doc' => 'declaracao_assistencia_familiar.pdf',
            ],
            [
                'assistant' => 'ME-3002', // Carla Moreira
                'type' => 'Formação',
                'from' => '2026-11-18',
                'until' => '2026-11-18',
                'days' => 1,
                'status' => 'justified',
                'doc' => 'comprovativo_formacao_primeiros_socorros.pdf',
            ],
            [
                'assistant' => 'ME-3001', // Jacinta Pires
                'type' => 'Consulta ou exame médico',
                'from' => '2026-11-25',
                'until' => '2026-11-25',
                'days' => 1,
                'status' => 'justified',
                'doc' => 'declaracao_presenca_clinica.pdf',
            ],
            [
                'assistant' => 'ME-2002', // Anabela Ferreira
                'type' => 'Consulta ou exame médico',
                'from' => '2026-12-01',
                'until' => '2026-12-01',
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
                    'document_path' => $item['doc'] ? "absences/{$assistant->internal_number}_{$item['doc']}" : null,
                    'created_by_user_id' => $adminId,
                    'justified_by_user_id' => $item['status'] === 'justified' ? $adminId : null,
                    'justification' => "Comprovativo validado pelos serviços administrativos da escola.",
                ]
            );
        }
    }
}
