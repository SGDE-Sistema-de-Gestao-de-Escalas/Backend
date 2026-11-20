<?php

namespace Database\Seeders;

use App\Models\Schools\School;
use App\Models\System\ActivityType;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class ActivityTypeSeeder extends Seeder
{
    /**
     * Atividades da EB1 de Vale Verde (1.º ciclo).
     * - Comuns às duas escolas: Acolhimento, Limpeza, Entrega de alunos (+ fallback Apoio Escola).
     * - Exclusivas do 1.º ciclo: Intervalo, Vigilância almoço.
     * - "Apoio Alunos NEE" NÃO é semeada (é criada ao vivo na demonstração).
     * - "Apoio Turmas/escola" foi removida por ser coberta pelo fallback.
     */
    public const EB1_ACTIVITIES = [
        ['name' => 'Apoio Escola', 'color' => '#6366F1', 'is_fallback' => true],
        ['name' => 'Acolhimento', 'color' => '#F59E0B', 'is_fallback' => false],
        ['name' => 'Intervalo', 'color' => '#10B981', 'is_fallback' => false],
        ['name' => 'Vigilância almoço', 'color' => '#A855F7', 'is_fallback' => false],
        ['name' => 'Limpeza', 'color' => '#8B5CF6', 'is_fallback' => false],
        ['name' => 'Entrega de alunos', 'color' => '#14B8A6', 'is_fallback' => false],
    ];

    /**
     * Atividades do JI de Vale Verde (Pré-escolar).
     * - Comuns às duas escolas: Acolhimento, Limpeza, Entrega de alunos (+ fallback Apoio Escola).
     * - Exclusivas do pré-escolar: Apoio Atividades JI, Apoio às Refeições, Vigilância da Sesta, Recreio JI.
     */
    public const JI_ACTIVITIES = [
        ['name' => 'Apoio Escola', 'color' => '#6366F1', 'is_fallback' => true],
        ['name' => 'Acolhimento', 'color' => '#F59E0B', 'is_fallback' => false],
        ['name' => 'Apoio Atividades JI', 'color' => '#3B82F6', 'is_fallback' => false],
        ['name' => 'Apoio às Refeições', 'color' => '#F97316', 'is_fallback' => false],
        ['name' => 'Vigilância da Sesta', 'color' => '#EC4899', 'is_fallback' => false],
        ['name' => 'Recreio JI', 'color' => '#06B6D4', 'is_fallback' => false],
        ['name' => 'Limpeza', 'color' => '#8B5CF6', 'is_fallback' => false],
        ['name' => 'Entrega de alunos', 'color' => '#14B8A6', 'is_fallback' => false],
    ];

    public function run(): void
    {
        School::query()->each(fn (School $school) => $this->seedForSchool($school));
    }

    public function seedForSchool(School $school): void
    {
        DB::transaction(function () use ($school) {
            $isJi = in_array($school->acronym, ['JIVV', 'EB23S', 'JIM'], true)
                || str_contains(strtolower($school->name), 'ji');
            $catalog = $isJi ? self::JI_ACTIVITIES : self::EB1_ACTIVITIES;

            // Remover atividades obsoletas/excluídas se existirem
            ActivityType::where('school_id', $school->id)
                ->whereIn('name', ['Apoio Turmas/escola', 'Apoio Turmas', 'Apoio NEE', 'Apoio Alunos NEE'])
                ->delete();

            foreach ($catalog as $item) {
                ActivityType::updateOrCreate(
                    [
                        'school_id' => $school->id,
                        'name' => $item['name'],
                    ],
                    [
                        'color' => $item['color'],
                        'is_system' => true,
                        'is_fallback' => $item['is_fallback'],
                        'active' => true,
                    ]
                );
            }
        });
    }
}
