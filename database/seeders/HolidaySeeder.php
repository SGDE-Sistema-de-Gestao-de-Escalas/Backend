<?php

namespace Database\Seeders;

use App\Services\Schedules\HolidayImportService;
use Illuminate\Database\Seeder;

class HolidaySeeder extends Seeder
{
    /**
     * Importa os feriados oficiais de Portugal para 2025 e 2026.
     * Feriados de 2027 NÃO são importados para permitir a importação
     * ao vivo pela equipa durante a apresentação prática.
     */
    public function run(): void
    {
        $service = app(HolidayImportService::class);
        $service->import(2025, null, null, true);
        $service->import(2026, null, null, true);
    }
}
