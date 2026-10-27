<?php

namespace Database\Seeders;

use App\Models\Assistants\Assistant;
use App\Models\Schedules\ScheduleRule;
use App\Models\Schools\School;
use App\Models\System\ActivityType;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Seeder de Regras de Horário e Cobertura (Schedule Rules) por escola.
 *
 * Horários e tipos definidos de acordo com as especificações da escola:
 *  - 07:30 às 08:30: "Acolhimento" (período antes das 8h30) -> Obrigatório (mandatory)
 *  - 08:45 às 10:15: "Apoio Turmas/escola" -> Facultativo (optional)
 *  - 10:15 às 10:45: "Intervalo" (dos miúdos) -> Obrigatório (mandatory)
 *  - 10:45 às 12:15: "Apoio Turmas/escola" -> Facultativo (optional)
 *  - 12:15 às 13:30: "Vigilância almoço" -> Facultativo (optional)
 *  - 13:30 às 15:00: "Apoio Turmas/escola" -> Facultativo (optional)
 *  - 15:00 às 15:30: "Intervalo" (dos miúdos) -> Obrigatório (mandatory)
 *  - 15:30 às 17:30: "Limpeza" -> Facultativo (optional)
 *  - 17:30 às 18:00: "Entrega de alunos" -> Obrigatório (mandatory)
 *
 * Todos os funcionários da escola ficam marcados como aptos na lista de prioridades.
 */
class ScheduleRuleSeeder extends Seeder
{
    /** Início do ano letivo */
    private const VALID_FROM = '2026-09-01';

    /** Segunda (1) a sexta (5) */
    private const WEEK_DAYS = [0, 1, 2, 3, 4];

    /**
     * Regras a criar para cada escola.
     *
     * @var array<int, array{activity: string, period_start: string, period_end: string, type: 'mandatory'|'optional', min: int}>
     */
    public const RULES = [
        [
            'activity' => 'Acolhimento',
            'period_start' => '07:30',
            'period_end' => '08:30',
            'type' => 'mandatory',
            'min' => 1,
        ],
        [
            'activity' => 'Apoio Turmas/escola',
            'period_start' => '08:45',
            'period_end' => '10:15',
            'type' => 'optional',
            'min' => 1,
        ],
        [
            'activity' => 'Intervalo',
            'period_start' => '10:15',
            'period_end' => '10:45',
            'type' => 'mandatory',
            'min' => 1,
        ],
        [
            'activity' => 'Apoio Turmas/escola',
            'period_start' => '10:45',
            'period_end' => '12:15',
            'type' => 'optional',
            'min' => 1,
        ],
        [
            'activity' => 'Vigilância almoço',
            'period_start' => '12:15',
            'period_end' => '13:30',
            'type' => 'optional',
            'min' => 1,
        ],
        [
            'activity' => 'Apoio Turmas/escola',
            'period_start' => '13:30',
            'period_end' => '15:00',
            'type' => 'optional',
            'min' => 1,
        ],
        [
            'activity' => 'Intervalo',
            'period_start' => '15:00',
            'period_end' => '15:30',
            'type' => 'mandatory',
            'min' => 1,
        ],
        [
            'activity' => 'Limpeza',
            'period_start' => '15:30',
            'period_end' => '17:30',
            'type' => 'optional',
            'min' => 1,
        ],
        [
            'activity' => 'Entrega de alunos',
            'period_start' => '17:30',
            'period_end' => '18:00',
            'type' => 'mandatory',
            'min' => 1,
        ],
    ];

    public function run(): void
    {
        $schools = School::all();

        if ($schools->isEmpty()) {
            $this->call(SchoolSeeder::class);
            $schools = School::all();
        }

        foreach ($schools as $school) {
            $this->seedRulesForSchool($school);
        }
    }

    public function seedRulesForSchool(School $school): void
    {
        DB::transaction(function () use ($school) {
            // Obter todos os assistentes ativos da escola para colocar como aptos
            $assistants = Assistant::where('school_id', $school->id)
                ->forScheduleGeneration()
                ->orderBy('admission_date')
                ->orderBy('id')
                ->get();

            if ($assistants->isEmpty()) {
                $assistants = Assistant::where('school_id', $school->id)->get();
            }

            foreach (self::RULES as $item) {
                $activity = $this->resolveActivityType($school, $item['activity']);

                if (! $activity) {
                    continue;
                }

                $rule = ScheduleRule::updateOrCreate(
                    [
                        'school_id' => $school->id,
                        'activity_type_id' => $activity->id,
                        'period_start' => $item['period_start'],
                        'period_end' => $item['period_end'],
                    ],
                    [
                        'min' => $item['min'],
                        'type' => $item['type'],
                        'valid_from' => self::VALID_FROM,
                        'valid_until' => null,
                        'days_of_week' => self::WEEK_DAYS,
                    ]
                );

                // Colocar todos os funcionários da escola como aptos na lista com prioridade sequencial
                $syncData = [];
                foreach ($assistants as $idx => $assistant) {
                    $syncData[$assistant->id] = ['priority' => $idx + 1];
                }

                $rule->assistants()->sync($syncData);
            }
        });
    }

    private function resolveActivityType(School $school, string $activityName): ?ActivityType
    {
        if ($activityName === 'Apoio Turmas/escola') {
            $activity = ActivityType::where('school_id', $school->id)
                ->where(function ($q) {
                    $q->where('name', 'Apoio Turmas/escola')
                      ->orWhere('name', 'Apoio Turmas');
                })
                ->where('is_fallback', false)
                ->first();

            if ($activity) {
                if ($activity->name !== 'Apoio Turmas/escola') {
                    $activity->update(['name' => 'Apoio Turmas/escola']);
                }
                return $activity;
            }

            return ActivityType::create([
                'school_id' => $school->id,
                'name' => 'Apoio Turmas/escola',
                'color' => '#3B82F6',
                'is_system' => true,
                'is_fallback' => false,
                'active' => true,
            ]);
        }

        $activity = ActivityType::where('school_id', $school->id)
            ->where('name', $activityName)
            ->where('is_fallback', false)
            ->first();

        if ($activity) {
            return $activity;
        }

        $colors = [
            'Acolhimento' => '#F59E0B',
            'Intervalo' => '#10B981',
            'Vigilância almoço' => '#A855F7',
            'Limpeza' => '#8B5CF6',
            'Entrega de alunos' => '#14B8A6',
        ];

        return ActivityType::create([
            'school_id' => $school->id,
            'name' => $activityName,
            'color' => $colors[$activityName] ?? '#6366F1',
            'is_system' => true,
            'is_fallback' => false,
            'active' => true,
        ]);
    }
}
