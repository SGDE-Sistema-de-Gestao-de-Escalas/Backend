<?php

namespace Database\Seeders;

use App\Models\Schools\School;
use App\Models\Schools\SchoolOperatingRule;
use App\Models\Schools\SchoolOperatingRuleDay;
use Illuminate\Database\Seeder;

/**
 * Horário de funcionamento fixo das escolas (segunda a sexta):
 *
 *  - EB1VV / ESPL (1.º ciclo):   07:30 – 18:00
 *  - JIVV  / EB23S (pré-escolar): 07:30 – 18:30
 *
 * Vigência desde 2025-09-01 para abranger todo o histórico.
 */
class SchoolOperatingRuleSeeder extends Seeder
{
    private const VALID_FROM = '2025-09-01';

    /** Segunda (0) a sexta (4) */
    private const WEEK_DAYS = [0, 1, 2, 3, 4];

    private const CLOSING_TIMES = [
        'EB1VV' => '18:00',
        'ESPL' => '18:00',
        'JIVV' => '18:30',
        'EB23S' => '18:30',
    ];

    public function run(): void
    {
        if (! School::query()->whereIn('acronym', array_keys(self::CLOSING_TIMES))->exists()) {
            $this->call(SchoolSeeder::class);
        }

        foreach (self::CLOSING_TIMES as $acronym => $closingTime) {
            $school = School::where('acronym', $acronym)->first();

            if (! $school) {
                continue;
            }

            $rule = SchoolOperatingRule::firstOrCreate([
                'school_id' => $school->id,
                'valid_from' => self::VALID_FROM,
            ]);

            foreach (self::WEEK_DAYS as $dayOfWeek) {
                SchoolOperatingRuleDay::updateOrCreate(
                    [
                        'school_operating_rule_id' => $rule->id,
                        'day_of_week' => $dayOfWeek,
                    ],
                    [
                        'opening_time' => '07:30',
                        'closing_time' => $closingTime,
                        'lunch_start' => '12:00',
                        'lunch_end' => '14:00',
                        'lunch_duration_minutes' => 60,
                    ]
                );
            }
        }
    }
}
