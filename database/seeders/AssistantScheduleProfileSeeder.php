<?php

namespace Database\Seeders;

use App\Models\Assistants\Assistant;
use App\Models\Schedules\AssistantScheduleProfile;
use Illuminate\Database\Seeder;

/**
 * Horários oficiais dos assistentes operacionais (segunda a sexta).
 *
 * Todos os assistentes cumprem rigorosamente turnos de 7 horas diárias de trabalho
 * e 1 hora de pausa de almoço (duração total decorrida de 8 horas certas).
 *
 *  - 1.º ciclo (EB1 de Vale Verde - EB1VV): Horário fixo por assistente.
 *  - Pré-escolar (JI de Vale Verde - JIVV): Horário rotativo quinzenal em pares.
 *
 * Vigência desde 2025-09-01 para garantir histórico de escalas e relatórios.
 */
class AssistantScheduleProfileSeeder extends Seeder
{
    private const VALID_FROM = '2025-09-01';

    /** Segunda a sexta (0 = segunda ... 4 = sexta) */
    private const WEEK_DAYS = [0, 1, 2, 3, 4];

    public function run(): void
    {
        $this->call(AssistantSeeder::class);

        // 1.º ciclo (EB1 de Vale Verde): Horário fixo (7h de trabalho + 1h almoço = 8h)
        // [Entrada, Saída, Início Almoço, Fim Almoço]
        //
        // Calibração exata para a apresentação:
        // - 07:45–08:30 (Limpeza min 3): ME-2001, ME-2002, ME-2003 (3/3 -> 0 défices!)
        // - 12:15–13:30 (Vigilância almoço min 4): apenas ME-2002, ME-2004 e ME-2005 estão livres (3/4).
        //   Quando o novo assistente (08:30–16:30, almoço 13:30–14:30) for adicionado -> 4/4 (0 défices!).
        //   Quando o novo assistente faltar a 7 de Dezembro -> volta a 3/4 -> ÚNICO alerta: Vigilância almoço!
        $fixed = [
            'ME-2001' => ['07:30', '15:30', '12:30', '13:30'], // Beatriz Almeida (7h trabalho)
            'ME-2002' => ['07:45', '15:45', '13:30', '14:30'], // Cláudia Teixeira (7h trabalho - livre 12:15–13:30)
            'ME-2003' => ['07:45', '15:45', '12:45', '13:45'], // Inês Monteiro (7h trabalho - cobre Limpeza 07:45)
            'ME-2004' => ['09:30', '17:30', '13:30', '14:30'], // Sofia Marques (7h trabalho - livre 12:15–13:30)
            'ME-2005' => ['10:00', '18:00', '13:45', '14:45'], // Raquel Nogueira (7h trabalho - livre 12:15–13:30)
            'ME-2006' => ['10:00', '18:00', '12:45', '13:45'], // Helena Cardoso (7h trabalho)
        ];

        foreach ($fixed as $internalNumber => $shift) {
            $this->createProfile($internalNumber, 'fixo', null, ['A' => $shift]);
        }

        // Pré-escolar (JI de Vale Verde): Horário rotativo quinzenal em pares (7h trabalho + 1h almoço = 8h)
        // Calibração a 7 de Dezembro de 2026 (index 1 de rotação desde 2025-09-01):
        // - ME-3002 (Mariana Correia): Turno A (08:30–16:30, almoço 13:30–14:30) -> IDÊNTICO ao novo assistente!
        // - ME-3003 (Catarina Mendes): Turno A (07:30–15:30, almoço 12:00–13:00) -> cobre Acolhimento 07:30 e Sesta 13:00–14:30
        // - ME-3004 (Lúcia Gonçalves): Turno B (09:30–17:30, almoço 12:00–13:00) -> cobre Sesta, Recreio JI e Limpeza
        // - ME-3001 (Teresa Lopes):    Turno B (10:30–18:30, almoço 14:00–15:00) -> cobre Recreio JI, Limpeza e Entrega 18:30
        // => Ao transferir Mariana Correia para a EB1 a 7/Dez, o JI mantém 0 défices obrigatórios!
        $rotating = [
            // Par 1 (Abertura 07:30 / Fecho 18:30)
            'ME-3001' => ['A', [
                'A' => ['07:30', '15:30', '12:00', '13:00'],
                'B' => ['10:30', '18:30', '14:00', '15:00'],
            ]],
            'ME-3003' => ['B', [
                'A' => ['07:30', '15:30', '12:00', '13:00'],
                'B' => ['10:30', '18:30', '14:00', '15:00'],
            ]],
            // Par 2 (Turnos Intermédios — ME-3002 Mariana Correia é a candidata ideal para 7 de Dezembro)
            'ME-3002' => ['B', [
                'A' => ['08:30', '16:30', '13:30', '14:30'],
                'B' => ['09:30', '17:30', '12:00', '13:00'],
            ]],
            'ME-3004' => ['A', [
                'A' => ['08:30', '16:30', '13:30', '14:30'],
                'B' => ['09:30', '17:30', '12:00', '13:00'],
            ]],
        ];

        foreach ($rotating as $internalNumber => [$startsWith, $shifts]) {
            $this->createProfile($internalNumber, 'rotativo', $startsWith, $shifts);
        }
    }

    private function createProfile(string $internalNumber, string $type, ?string $startsWith, array $shifts): void
    {
        $assistant = Assistant::where('internal_number', $internalNumber)->first();

        if (! $assistant) {
            $this->command?->warn("Assistente {$internalNumber} não encontrado; horário ignorado.");
            return;
        }

        $existingProfile = AssistantScheduleProfile::where('assistant_id', $assistant->id)->first();
        if ($existingProfile) {
            $existingProfile->shifts()->delete();
            $existingProfile->delete();
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
