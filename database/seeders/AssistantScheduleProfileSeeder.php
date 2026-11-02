<?php

namespace Database\Seeders;

use App\Models\Assistants\Assistant;
use App\Models\Schedules\AssistantScheduleProfile;
use Illuminate\Database\Seeder;

/**
 * Horários dos assistentes da folha "Escalas" (ver AssistantSeeder).
 *
 *  - 1.º ciclo (ESPL): horário fixo, um turno por assistente.
 *  - Pré-escolar (EB23S): horário rotativo quinzenal. Em cada par, as duas
 *    assistentes trocam de turno (A ↔ B) de 15 em 15 dias:
 *      · Filipa ↔ Jacinta   (A 08:00–15:45, B 10:45–18:30)
 *      · Diana  ↔ Carla     (A 08:45–16:30, B 10:00–17:45)
 *    Na primeira quinzena (tabela 2 da folha) a Jacinta e a Carla fazem o
 *    turno A e a Filipa e a Diana o B.
 *
 * As horas são as da folha: entrada na primeira linha preenchida e saída na
 * última linha preenchida (ex.: Patrícia 07:30–15:15).
 *
 * Cada turno tem a pausa de almoço do respetivo assistente (hora de início e
 * de fim; a duração é a diferença entre as duas). O horário de funcionamento
 * das escolas tem a sua própria janela de almoço (SchoolOperatingRuleSeeder).
 *
 * Dias da semana dos assistentes: 0 = segunda ... 6 = domingo (segunda a
 * sexta = 0..4).
 *
 * Pode correr várias vezes: só cria o horário a quem ainda não tem nenhum,
 * para nunca repor alterações feitas por um admin.
 */
class AssistantScheduleProfileSeeder extends Seeder
{
    /** Início da vigência (uma segunda-feira) e âncora da rotação quinzenal. */
    private const VALID_FROM = '2026-10-12';

    /** Segunda a sexta. */
    private const WEEK_DAYS = [0, 1, 2, 3, 4];

    public function run(): void
    {
        $this->call(AssistantSeeder::class);

        // 1.º ciclo: horário fixo. Turno => [entrada, saída, início do almoço, fim do almoço]
        $fixed = [
            'ME-2001' => ['07:30', '15:15', '12:30', '13:30'], // Patrícia
            'ME-2002' => ['07:45', '15:30', '13:30', '14:30'], // Anabela
            'ME-2003' => ['08:00', '15:45', '12:45', '13:45'], // Joana
            'ME-2004' => ['10:00', '18:00', '13:45', '14:45'], // MJB
            'ME-2005' => ['10:00', '18:00', '13:45', '14:45'], // Marta
            'ME-2006' => ['10:00', '18:00', '13:45', '14:45'], // Sandra
        ];

        foreach ($fixed as $internalNumber => $shift) {
            $this->createProfile($internalNumber, 'fixo', null, ['A' => $shift]);
        }

        // Pré-escolar: rotativo quinzenal. Almoço diferente em cada turno.
        // starts_with => turnos A e B [entrada, saída, início do almoço, fim do almoço]
        $rotating = [
            // Jacinta
            'ME-3001' => ['A', [
                'A' => ['08:00', '15:45', '12:00', '13:00'],
                'B' => ['10:45', '18:30', '14:00', '15:00'],
            ]],
            // Filipa
            'ME-3003' => ['B', [
                'A' => ['08:00', '15:45', '14:00', '15:00'],
                'B' => ['10:45', '18:30', '12:00', '13:00'],
            ]],
            // Carla
            'ME-3002' => ['A', [
                'A' => ['08:45', '16:30', '13:00', '14:00'],
                'B' => ['10:00', '17:45', '13:30', '14:30'],
            ]],
            // Diana
            'ME-3004' => ['B', [
                'A' => ['08:45', '16:30', '13:30', '14:30'],
                'B' => ['10:00', '17:45', '13:00', '14:00'],
            ]],
        ];

        foreach ($rotating as $internalNumber => [$startsWith, $shifts]) {
            $this->createProfile($internalNumber, 'rotativo', $startsWith, $shifts);
        }
    }

    /**
     * @param  array<string, array{0: string, 1: string, 2: string, 3: string}>  $shifts
     *                                                                                    etiqueta => [entrada, saída, início do almoço, fim do almoço]
     */
    private function createProfile(string $internalNumber, string $type, ?string $startsWith, array $shifts): void
    {
        $assistant = Assistant::where('internal_number', $internalNumber)->first();

        if (! $assistant) {
            $this->command?->warn("Assistente {$internalNumber} não encontrado; horário ignorado.");

            return;
        }

        if (AssistantScheduleProfile::where('assistant_id', $assistant->id)->exists()) {
            return;
        }

        $profile = AssistantScheduleProfile::create([
            'assistant_id' => $assistant->id,
            'type' => $type,
            'rotation_period' => $type === 'rotativo' ? 'biweekly' : null,
            'starts_with' => $startsWith,
            'valid_from' => self::VALID_FROM,
            'valid_until' => null,
        ]);

        foreach ($shifts as $label => [$entry, $exit, $lunchStart, $lunchEnd]) {
            $shift = $profile->shifts()->create([
                'shift_label' => $label,
                'entry_time' => $entry,
                'exit_time' => $exit,
                'lunch_enabled' => true,
                'lunch_start' => $lunchStart,
                'lunch_end' => $lunchEnd,
                'lunch_duration_minutes' => $this->minutesBetween($lunchStart, $lunchEnd),
            ]);

            foreach (self::WEEK_DAYS as $dayOfWeek) {
                $shift->shiftDays()->create(['day_of_week' => $dayOfWeek]);
            }
        }
    }

    private function minutesBetween(string $start, string $end): int
    {
        [$startHour, $startMinute] = array_map('intval', explode(':', $start));
        [$endHour, $endMinute] = array_map('intval', explode(':', $end));

        return ($endHour * 60 + $endMinute) - ($startHour * 60 + $startMinute);
    }
}
