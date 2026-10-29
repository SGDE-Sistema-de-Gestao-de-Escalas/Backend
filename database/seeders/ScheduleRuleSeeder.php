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
 * Semeia a configuração real das regras de cobertura da EB1 Miosótis (ESPL)
 * — incluindo "Apoio NEE", prioridades específicas por assistente e dias da semana —
 * bem como o conjunto predefinido de regras para as restantes escolas (ex: JI Miosótis / EB23S).
 */
class ScheduleRuleSeeder extends Seeder
{
    /** Início do ano letivo */
    private const VALID_FROM = '2026-09-01';

    /** Segunda (0) a sexta (4) */
    private const WEEK_DAYS = [0, 1, 2, 3, 4];

    /**
     * Regras configuradas para a EB1 Miosótis (código ESPL).
     *
     * @var array<int, array{
     *     activity: string,
     *     period_start: string,
     *     period_end: string,
     *     type: 'mandatory'|'optional',
     *     min: int,
     *     days_of_week: array<int, int>,
     *     valid_from: string,
     *     priorities: array<string, int>
     * }>
     */
    public const ESPL_RULES = [
        [
            'activity' => 'Limpeza',
            'period_start' => '07:45',
            'period_end' => '08:30',
            'type' => 'mandatory',
            'min' => 3,
            'days_of_week' => [0, 1, 2, 3, 4],
            'valid_from' => '2026-10-28',
            'priorities' => [
                'ME-2002' => 1,
                'ME-2003' => 2,
                'ME-2005' => 3,
                'ME-2004' => 4,
                'ME-2006' => 6,
            ],
        ],
        [
            'activity' => 'Acolhimento',
            'period_start' => '08:30',
            'period_end' => '08:45',
            'type' => 'mandatory',
            'min' => 3,
            'days_of_week' => [0, 1, 2, 3, 4],
            'valid_from' => '2026-09-01',
            'priorities' => [
                'ME-2002' => 2,
                'ME-2003' => 3,
                'ME-2004' => 4,
                'ME-2005' => 5,
                'ME-2006' => 6,
            ],
        ],
        [
            'activity' => 'Apoio Turmas/escola',
            'period_start' => '08:45',
            'period_end' => '10:15',
            'type' => 'optional',
            'min' => 3,
            'days_of_week' => [0, 1, 2, 3, 4],
            'valid_from' => '2026-09-01',
            'priorities' => [
                'ME-2002' => 2,
                'ME-2003' => 3,
                'ME-2004' => 4,
                'ME-2005' => 5,
                'ME-2006' => 6,
            ],
        ],
        [
            'activity' => 'Apoio NEE',
            'period_start' => '08:45',
            'period_end' => '12:15',
            'type' => 'mandatory',
            'min' => 1,
            'days_of_week' => [3],
            'valid_from' => '2026-10-28',
            'priorities' => [
                'ME-2005' => 1,
                'ME-2003' => 2,
            ],
        ],
        [
            'activity' => 'Intervalo',
            'period_start' => '10:15',
            'period_end' => '10:45',
            'type' => 'mandatory',
            'min' => 5,
            'days_of_week' => [0, 1, 2, 3, 4],
            'valid_from' => '2026-09-01',
            'priorities' => [
                'ME-2002' => 2,
                'ME-2003' => 3,
                'ME-2004' => 4,
                'ME-2005' => 5,
                'ME-2006' => 6,
            ],
        ],
        [
            'activity' => 'Apoio Turmas/escola',
            'period_start' => '10:45',
            'period_end' => '12:15',
            'type' => 'optional',
            'min' => 3,
            'days_of_week' => [0, 1, 2, 3, 4],
            'valid_from' => '2026-09-01',
            'priorities' => [
                'ME-2002' => 2,
                'ME-2003' => 3,
                'ME-2004' => 4,
                'ME-2005' => 5,
                'ME-2006' => 6,
            ],
        ],
        [
            'activity' => 'Apoio NEE',
            'period_start' => '10:45',
            'period_end' => '12:15',
            'type' => 'mandatory',
            'min' => 1,
            'days_of_week' => [0, 1, 2],
            'valid_from' => '2026-10-28',
            'priorities' => [
                'ME-2005' => 1,
                'ME-2003' => 2,
            ],
        ],
        [
            'activity' => 'Vigilância almoço',
            'period_start' => '12:15',
            'period_end' => '13:30',
            'type' => 'mandatory',
            'min' => 4,
            'days_of_week' => [0, 1, 2, 3, 4],
            'valid_from' => '2026-09-01',
            'priorities' => [
                'ME-2002' => 2,
                'ME-2003' => 3,
                'ME-2004' => 4,
                'ME-2005' => 5,
                'ME-2006' => 6,
            ],
        ],
        [
            'activity' => 'Apoio Turmas/escola',
            'period_start' => '13:30',
            'period_end' => '15:00',
            'type' => 'optional',
            'min' => 3,
            'days_of_week' => [0, 1, 2, 3, 4],
            'valid_from' => '2026-09-01',
            'priorities' => [
                'ME-2002' => 2,
                'ME-2003' => 3,
                'ME-2004' => 4,
                'ME-2005' => 5,
                'ME-2006' => 6,
            ],
        ],
        [
            'activity' => 'Apoio NEE',
            'period_start' => '13:30',
            'period_end' => '15:00',
            'type' => 'mandatory',
            'min' => 1,
            'days_of_week' => [0],
            'valid_from' => '2026-10-28',
            'priorities' => [
                'ME-2005' => 1,
                'ME-2003' => 2,
            ],
        ],
        [
            'activity' => 'Intervalo',
            'period_start' => '15:00',
            'period_end' => '15:30',
            'type' => 'mandatory',
            'min' => 4,
            'days_of_week' => [0, 1, 2, 3, 4],
            'valid_from' => '2026-09-01',
            'priorities' => [
                'ME-2002' => 2,
                'ME-2003' => 3,
                'ME-2005' => 4,
                'ME-2006' => 5,
            ],
        ],
        [
            'activity' => 'Entrega de alunos',
            'period_start' => '15:00',
            'period_end' => '15:30',
            'type' => 'mandatory',
            'min' => 1,
            'days_of_week' => [0, 1, 2, 3, 4],
            'valid_from' => '2026-09-01',
            'priorities' => [
                'ME-2004' => 1,
                'ME-2002' => 3,
                'ME-2003' => 4,
                'ME-2005' => 5,
                'ME-2006' => 6,
            ],
        ],
        [
            'activity' => 'Limpeza',
            'period_start' => '15:30',
            'period_end' => '17:30',
            'type' => 'mandatory',
            'min' => 2,
            'days_of_week' => [0, 1, 2, 3, 4],
            'valid_from' => '2026-09-01',
            'priorities' => [
                'ME-2002' => 2,
                'ME-2003' => 3,
                'ME-2004' => 4,
                'ME-2005' => 5,
                'ME-2006' => 6,
            ],
        ],
        [
            'activity' => 'Entrega de alunos',
            'period_start' => '17:30',
            'period_end' => '17:45',
            'type' => 'mandatory',
            'min' => 1,
            'days_of_week' => [0, 1, 2, 3, 4],
            'valid_from' => '2026-10-28',
            'priorities' => [
                'ME-2002' => 1,
                'ME-2003' => 2,
                'ME-2005' => 3,
                'ME-2004' => 4,
                'ME-2006' => 6,
            ],
        ],
    ];

    /**
     * Regras base para as restantes escolas (ex: JI Miosótis / EB23S).
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
            $assistants = Assistant::where('school_id', $school->id)
                ->forScheduleGeneration()
                ->orderBy('admission_date')
                ->orderBy('id')
                ->get();

            if ($assistants->isEmpty()) {
                $assistants = Assistant::where('school_id', $school->id)->get();
            }

            $assistantsByInternalNumber = $assistants->keyBy('internal_number');
            $isEspl = $school->acronym === 'ESPL';
            $rulesConfig = $isEspl ? self::ESPL_RULES : self::RULES;
            $keptRuleIds = [];

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
                        'valid_from' => $item['valid_from'] ?? self::VALID_FROM,
                        'valid_until' => null,
                        'days_of_week' => $item['days_of_week'] ?? self::WEEK_DAYS,
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

                // Fallback caso os números internos não coincidam (ex: testes com factories)
                if (empty($syncData)) {
                    foreach ($assistants as $idx => $assistant) {
                        $syncData[$assistant->id] = ['priority' => $idx + 1];
                    }
                }

                $rule->assistants()->sync($syncData);
            }

            if ($isEspl && ! empty($keptRuleIds)) {
                ScheduleRule::where('school_id', $school->id)
                    ->whereNotIn('id', $keptRuleIds)
                    ->delete();
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
            'Apoio NEE' => '#06B6D4',
        ];

        $isCustom = $activityName === 'Apoio NEE';

        return ActivityType::create([
            'school_id' => $school->id,
            'name' => $activityName,
            'color' => $colors[$activityName] ?? '#6366F1',
            'is_system' => ! $isCustom,
            'is_fallback' => false,
            'active' => true,
        ]);
    }
}
