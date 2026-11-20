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
 *  - 1.º ciclo (EB1 Miosótis - ESPL): Horário fixo por assistente.
 *  - Pré-escolar (JI Miosótis - EB23S): Horário rotativo quinzenal em pares.
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

        // 1.º ciclo (EB1 Miosótis): Horário fixo (7h de trabalho + 1h almoço = 8h)
        // [Entrada, Saída, Início Almoço, Fim Almoço]
        $fixed = [
            'ME-2001' => ['07:30', '15:30', '12:30', '13:30'], // Patrícia Neves (7h trabalho)
            'ME-2002' => ['07:45', '15:45', '13:30', '14:30'], // Anabela Ferreira (7h trabalho)
            'ME-2003' => ['08:00', '16:00', '12:45', '13:45'], // Joana Antunes (7h trabalho)
            'ME-2004' => ['09:30', '17:30', '13:30', '14:30'], // Maria João Bastos (7h trabalho)
            'ME-2005' => ['10:00', '18:00', '13:45', '14:45'], // Marta Rodrigues (7h trabalho)
            'ME-2006' => ['10:00', '18:00', '12:45', '13:45'], // Sandra Carvalho (7h trabalho)
        ];

        foreach ($fixed as $internalNumber => $shift) {
            $this->createProfile($internalNumber, 'fixo', null, ['A' => $shift]);
        }

        // Pré-escolar (JI Miosótis): Horário rotativo quinzenal (todos com 7h trabalho + 1h almoço)
        // starts_with calibrado para que a 7 de Dezembro de 2026:
        // - Carla Moreira esteja no Turno A (08:30–16:30, almoço 13:30–14:30), idêntico ao novo assistente!
        $rotating = [
            // Jacinta Pires (Turno A: 08:00–16:00; Turno B: 10:30–18:30)
            'ME-3001' => ['A', [
                'A' => ['08:00', '16:00', '12:00', '13:00'],
                'B' => ['10:30', '18:30', '14:00', '15:00'],
            ]],
            // Filipa Santos (Turno A: 08:00–16:00; Turno B: 10:30–18:30)
            'ME-3003' => ['B', [
                'A' => ['08:00', '16:00', '14:00', '15:00'],
                'B' => ['10:30', '18:30', '12:00', '13:00'],
            ]],
            // Carla Moreira (Candidata ideal: no dia 7 Dez está no Turno A 08:30–16:30, almoço 13:30–14:30)
            'ME-3002' => ['B', [
                'A' => ['08:30', '16:30', '13:30', '14:30'],
                'B' => ['09:45', '17:45', '12:30', '13:30'],
            ]],
            // Diana Ribeiro (Turno A: 08:30–16:30; Turno B: 09:45–17:45)
            'ME-3004' => ['A', [
                'A' => ['08:30', '16:30', '12:30', '13:30'],
                'B' => ['09:45', '17:45', '13:30', '14:30'],
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

        // Se já existir perfil, remove os turnos associados para sincronizar os horários corretos de 7h
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
