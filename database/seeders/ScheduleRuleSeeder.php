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
 * Vigência desde 2025-09-01 para assegurar histórico analítico nos relatórios.
 * "Apoio Alunos NEE" NÃO é semeada (é adicionada na demonstração ao vivo pela Pessoa 3).
 * "Apoio Turmas/escola" foi removida por ser redundante com a atividade de fallback.
 */
class ScheduleRuleSeeder extends Seeder
{
    private const VALID_FROM = '2025-09-01';

    /** Segunda (0) a sexta (4) */
    private const WEEK_DAYS = [0, 1, 2, 3, 4];

    /**
     * Regras da EB1 de Vale Verde (EB1VV / ESPL).
     */
    public const ESPL_RULES = [
        [
            'activity' => 'Limpeza',
            'period_start' => '07:45',
            'period_end' => '08:30',
            'type' => 'mandatory',
            'min' => 3,
            'priorities' => [
                'ME-2001' => 1,
                'ME-2002' => 2,
                'ME-2003' => 3,
                'ME-2004' => 4,
                'ME-2005' => 5,
                'ME-2006' => 6,
            ],
        ],
        [
            'activity' => 'Acolhimento',
            'period_start' => '08:30',
            'period_end' => '08:45',
            'type' => 'mandatory',
            'min' => 3,
            'priorities' => [
                'ME-2001' => 1,
                'ME-2002' => 2,
                'ME-2003' => 3,
                'ME-2004' => 4,
                'ME-2005' => 5,
                'ME-2006' => 6,
            ],
        ],
        [
            'activity' => 'Intervalo',
            'period_start' => '10:15',
            'period_end' => '10:45',
            'type' => 'mandatory',
            'min' => 4,
            'priorities' => [
                'ME-2001' => 1,
                'ME-2002' => 2,
                'ME-2003' => 3,
                'ME-2004' => 4,
                'ME-2005' => 5,
                'ME-2006' => 6,
            ],
        ],
        [
            'activity' => 'Vigilância almoço',
            'period_start' => '12:15',
            'period_end' => '13:30',
            'type' => 'mandatory',
            'min' => 4,
            'priorities' => [
                'ME-2002' => 1,
                'ME-2004' => 2,
                'ME-2005' => 3,
                'ME-2003' => 4,
                'ME-2006' => 5,
            ],
        ],
        [
            'activity' => 'Intervalo',
            'period_start' => '15:00',
            'period_end' => '15:30',
            'type' => 'mandatory',
            'min' => 3,
            'priorities' => [
                'ME-2001' => 1,
                'ME-2002' => 2,
                'ME-2003' => 3,
                'ME-2005' => 4,
                'ME-2006' => 5,
                'ME-2004' => 6,
            ],
        ],
        [
            'activity' => 'Entrega de alunos',
            'period_start' => '15:00',
            'period_end' => '15:30',
            'type' => 'mandatory',
            'min' => 1,
            'priorities' => [
                'ME-2004' => 1,
                'ME-2005' => 2,
                'ME-2006' => 3,
                'ME-2002' => 4,
                'ME-2003' => 5,
            ],
        ],
        [
            'activity' => 'Limpeza',
            'period_start' => '15:30',
            'period_end' => '17:30',
            'type' => 'mandatory',
            'min' => 2,
            'priorities' => [
                'ME-2004' => 1,
                'ME-2005' => 2,
                'ME-2006' => 3,
                'ME-2002' => 4,
                'ME-2003' => 5,
            ],
        ],
        [
            'activity' => 'Entrega de alunos',
            'period_start' => '17:30',
            'period_end' => '17:45',
            'type' => 'mandatory',
            'min' => 1,
            'priorities' => [
                'ME-2005' => 1,
                'ME-2006' => 2,
                'ME-2004' => 3,
            ],
        ],
    ];

    /**
     * Regras do JI de Vale Verde (JIVV / EB23S).
     */
    public const RULES = [
        [
            'activity' => 'Acolhimento',
            'period_start' => '07:30',
            'period_end' => '08:30',
            'type' => 'mandatory',
            'min' => 1,
            'priorities' => [
                'ME-3001' => 1,
                'ME-3003' => 2,
            ],
        ],
        [
            'activity' => 'Apoio Atividades JI',
            'period_start' => '09:00',
            'period_end' => '11:30',
            'type' => 'optional',
            'min' => 2,
            'priorities' => [
                'ME-3002' => 1,
                'ME-3004' => 2,
                'ME-3001' => 3,
                'ME-3003' => 4,
            ],
        ],
        [
            'activity' => 'Apoio às Refeições',
            'period_start' => '12:15',
            'period_end' => '13:30',
            'type' => 'optional',
            'min' => 1,
            'priorities' => [
                'ME-3001' => 1,
                'ME-3003' => 2,
                'ME-3004' => 3,
            ],
        ],
        [
            'activity' => 'Vigilância da Sesta',
            'period_start' => '13:00',
            'period_end' => '14:30',
            'type' => 'mandatory',
            'min' => 1,
            'priorities' => [
                'ME-3003' => 1,
                'ME-3001' => 2,
                'ME-3004' => 3,
            ],
        ],
        [
            'activity' => 'Recreio JI',
            'period_start' => '15:00',
            'period_end' => '15:30',
            'type' => 'mandatory',
            'min' => 2,
            'priorities' => [
                'ME-3001' => 1,
                'ME-3003' => 2,
                'ME-3004' => 3,
                'ME-3002' => 4,
            ],
        ],
        [
            'activity' => 'Limpeza',
            'period_start' => '16:30',
            'period_end' => '17:30',
            'type' => 'mandatory',
            'min' => 1,
            'priorities' => [
                'ME-3001' => 1,
                'ME-3003' => 2,
                'ME-3004' => 3,
                'ME-3002' => 4,
            ],
        ],
        [
            'activity' => 'Entrega de alunos',
            'period_start' => '17:30',
            'period_end' => '18:30',
            'type' => 'mandatory',
            'min' => 1,
            'priorities' => [
                'ME-3001' => 1,
                'ME-3003' => 2,
            ],
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
            $assistants = Assistant::where('school_id', $school->id)
                ->forScheduleGeneration()
                ->orderBy('admission_date')
                ->orderBy('id')
                ->get();

            if ($assistants->isEmpty()) {
                $assistants = Assistant::where('school_id', $school->id)->get();
            }

            $assistantsByInternalNumber = $assistants->keyBy('internal_number');
            $isEb1 = in_array($school->acronym, ['ESPL', 'EB1VV'], true);
            $rulesConfig = $isEb1 ? self::ESPL_RULES : self::RULES;
            $keptRuleIds = [];

            // Remover quaisquer regras obsoletas (ex: com Apoio Turmas ou Apoio NEE)
            $obsoleteActivityIds = ActivityType::where('school_id', $school->id)
                ->whereIn('name', ['Apoio Turmas/escola', 'Apoio Turmas', 'Apoio NEE', 'Apoio Alunos NEE'])
                ->pluck('id');

            if ($obsoleteActivityIds->isNotEmpty()) {
                ScheduleRule::where('school_id', $school->id)
                    ->whereIn('activity_type_id', $obsoleteActivityIds)
                    ->delete();
            }

            foreach ($rulesConfig as $item) {
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

                $keptRuleIds[] = $rule->id;

                $syncData = [];
                if (! empty($item['priorities']) && is_array($item['priorities'])) {
                    foreach ($item['priorities'] as $internalNumber => $priority) {
                        $matched = $assistantsByInternalNumber->get($internalNumber);
                        if ($matched) {
                            $syncData[$matched->id] = ['priority' => (int) $priority];
                        }
                    }
                }

                if (empty($syncData)) {
                    foreach ($assistants as $idx => $assistant) {
                        $syncData[$assistant->id] = ['priority' => $idx + 1];
                    }
                }

                $rule->assistants()->sync($syncData);
            }

            if (! empty($keptRuleIds)) {
                ScheduleRule::where('school_id', $school->id)
                    ->whereNotIn('id', $keptRuleIds)
                    ->delete();
            }
        });
    }

    private function resolveActivityType(School $school, string $activityName): ?ActivityType
    {
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
            'Apoio Atividades JI' => '#3B82F6',
            'Apoio às Refeições' => '#F97316',
            'Vigilância da Sesta' => '#EC4899',
            'Recreio JI' => '#06B6D4',
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
