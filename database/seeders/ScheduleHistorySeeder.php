<?php

namespace Database\Seeders;

use App\Models\Schedules\Schedule;
use App\Models\Schools\School;
use App\Services\Schedules\ScheduleGenerationService;
use Illuminate\Database\Seeder;

class ScheduleHistorySeeder extends Seeder
{
    /**
     * Semeia histórico de escalas geradas e publicadas para alimentar os relatórios (taxa de cobertura, etc.),
     * e gera o mês corrente de Dezembro de 2026 em estado de rascunho (draft) para a demonstração prática.
     */
    public function run(): void
    {
        $schools = School::all();
        if ($schools->isEmpty()) {
            return;
        }

        $service = app(ScheduleGenerationService::class);

        foreach ($schools as $school) {
            // 1. Gerar e publicar escalas para os meses anteriores do ano letivo 2026/2027
            $historicalRanges = [
                ['2026-09-01', '2026-09-30'],
                ['2026-10-01', '2026-10-31'],
                ['2026-11-01', '2026-11-30'],
            ];

            foreach ($historicalRanges as [$from, $to]) {
                try {
                    $service->generate($school->id, $from, $to, true);

                    // Publicar as escalas passadas para constarem nos relatórios com status 'published'
                    Schedule::where('school_id', $school->id)
                        ->whereDate('week_start', '<=', $to)
                        ->whereDate('week_end', '>=', $from)
                        ->where('status', 'draft')
                        ->update([
                            'status' => 'published',
                            'published_at' => now(),
                        ]);
                } catch (\Throwable $e) {
                    $this->command?->warn("Aviso ao gerar histórico para {$school->name} ({$from} a {$to}): " . $e->getMessage());
                }
            }

            // 2. Gerar o mês corrente de Dezembro de 2026 mantendo em estado de 'draft' (rascunho)
            try {
                $service->generate($school->id, '2026-12-01', '2026-12-31', true);
            } catch (\Throwable $e) {
                $this->command?->warn("Aviso ao gerar escala de Dezembro para {$school->name}: " . $e->getMessage());
            }
        }
    }
}
