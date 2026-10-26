<?php

namespace Database\Seeders;

use App\Models\Schools\School;
use App\Models\System\ActivityType;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class ActivityTypeSeeder extends Seeder
{
    /**
     * Atividades predefinidas por escola.
     * "Apoio Escola" é a atividade base (is_fallback = true) usada pelo motor
     * para preencher automaticamente os períodos livres do turno.
     *
     * @var array<int, array{name: string, color: string, is_fallback: bool}>
     */
    public const DEFAULTS = [
        ['name' => 'Apoio Escola', 'color' => '#6366F1', 'is_fallback' => true],
        ['name' => 'Acolhimento', 'color' => '#F59E0B', 'is_fallback' => false],
        ['name' => 'Apoio Turmas/escola', 'color' => '#3B82F6', 'is_fallback' => false],
        ['name' => 'Intervalo', 'color' => '#10B981', 'is_fallback' => false],
        ['name' => 'Vigilância almoço', 'color' => '#A855F7', 'is_fallback' => false],
        ['name' => 'Limpeza', 'color' => '#8B5CF6', 'is_fallback' => false],
        ['name' => 'Entrega de alunos', 'color' => '#14B8A6', 'is_fallback' => false],
    ];

    /**
     * Semeia as atividades predefinidas (incluindo a atividade fallback)
     * em todas as escolas existentes.
     */
    public function run(): void
    {
        School::query()->each(fn (School $school) => $this->seedForSchool($school));
    }

    /**
     * Cria ou atualiza as atividades predefinidas para uma escola específica.
     */
    public function seedForSchool(School $school): void
    {
        DB::transaction(function () use ($school) {
            // Atualizar "Apoio Turmas" para "Apoio Turmas/escola" se já existir
            ActivityType::where('school_id', $school->getKey())
                ->where('name', 'Apoio Turmas')
                ->update(['name' => 'Apoio Turmas/escola']);

            $alreadySeeded = ActivityType::where('school_id', $school->getKey())
                ->where('is_system', true)
                ->exists();

            if ($alreadySeeded) {
                $hasFallback = ActivityType::where('school_id', $school->getKey())
                    ->where('is_fallback', true)
                    ->exists();

                if (! $hasFallback) {
                    $updated = ActivityType::where('school_id', $school->getKey())
                        ->where('is_system', true)
                        ->where('name', 'Apoio Escola')
                        ->update([
                            'is_fallback' => true,
                            'active' => true,
                        ]);

                    if ($updated === 0) {
                        ActivityType::create([
                            'school_id' => $school->getKey(),
                            'name' => 'Apoio Escola',
                            'color' => '#6366F1',
                            'is_system' => true,
                            'is_fallback' => true,
                            'active' => true,
                        ]);
                    }
                }

                // Garantir que todas as atividades predefinidas existem
                foreach (self::DEFAULTS as $item) {
                    ActivityType::firstOrCreate(
                        [
                            'school_id' => $school->getKey(),
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

                return;
            }

            foreach (self::DEFAULTS as $item) {
                ActivityType::create([
                    'school_id' => $school->getKey(),
                    'name' => $item['name'],
                    'color' => $item['color'],
                    'is_system' => true,
                    'is_fallback' => $item['is_fallback'],
                    'active' => true,
                ]);
            }
        });
    }
}