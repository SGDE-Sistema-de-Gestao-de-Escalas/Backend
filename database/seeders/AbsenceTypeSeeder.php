<?php

namespace Database\Seeders;

use App\Models\Absences\AbsenceType;
use Illuminate\Database\Seeder;

class AbsenceTypeSeeder extends Seeder
{
    /**
     * Tipos de falta mais comuns. Seeder permanente: pode correr em qualquer
     * ambiente, as vezes que for preciso, sem duplicar. Cria só os que
     * faltam (pelo nome) e nunca altera os que já existem, para não repor
     * alterações feitas por um administrador.
     */
    public function run(): void
    {
        $types = [
            // Exigem documento comprovativo
            ['name' => 'Doença', 'requires_document' => true],
            ['name' => 'Consulta ou exame médico', 'requires_document' => true],
            ['name' => 'Assistência a familiar doente', 'requires_document' => true],
            ['name' => 'Acidente de trabalho', 'requires_document' => true],
            ['name' => 'Falecimento de familiar', 'requires_document' => true],
            ['name' => 'Casamento', 'requires_document' => true],
            ['name' => 'Licença parental', 'requires_document' => true],
            ['name' => 'Cumprimento de obrigação legal', 'requires_document' => true],
            ['name' => 'Prestação de provas de avaliação', 'requires_document' => true],
            ['name' => 'Doação de sangue', 'requires_document' => true],

            // Não exigem documento
            ['name' => 'Assuntos pessoais', 'requires_document' => false],
            ['name' => 'Formação', 'requires_document' => false],
            ['name' => 'Falta autorizada pela entidade empregadora', 'requires_document' => false],
            ['name' => 'Falta injustificada', 'requires_document' => false],
        ];

        foreach ($types as $type) {
            AbsenceType::firstOrCreate(
                ['name' => $type['name']],
                ['requires_document' => $type['requires_document']],
            );
        }
    }
}
