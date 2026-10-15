<?php

namespace Database\Seeders;

use App\Models\Schools\School;
use App\Models\Schools\SchoolOperatingRule;
use App\Models\Schools\SchoolOperatingRuleDay;
use Illuminate\Database\Seeder;

/**
 * Horário de funcionamento fixo das escolas (segunda a sexta), como na folha
 * "Escalas":
 *
 *  - ESPL (1.º ciclo): 07:30 – 18:00
 *  - EB23S (pré-escolar): 07:30 – 18:30 (última saída da folha: Filipa/Jacinta)
 *
 * O modelo exige uma janela de almoço; para testes usa-se 12:00 – 14:00 com
 * duração de 60 minutos (o admin define depois o valor real).
 *
 * Pode correr várias vezes: não duplica versões nem dias, e repõe as horas
 * desta versão. Não mexe em versões mais recentes criadas por um admin.
 */
class SchoolOperatingRuleSeeder extends Seeder
{
    /** Início da versão (começo do ano letivo). */
    private const VALID_FROM = '2026-09-01';

    /** Segunda (1) a sexta (5); 1 = segunda ... 7 = domingo. */
    private const WEEK_DAYS = [1, 2, 3, 4, 5];

    /**
     * acronym => hora de fecho
     *
     * @var array<string, string>
     */
    private const CLOSING_TIMES = [
        'ESPL' => '18:00',
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
