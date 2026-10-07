<?php

namespace Database\Seeders;

use App\Models\Schools\School;
use App\Services\ActivityTypeService;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class ActivityTypeSeeder extends Seeder
{
    /**
     * Cria as atividades predefinidas (pedidas pelo cliente) em todas as
     * escolas existentes. Pode correr várias vezes: não duplica. As escolas
     * criadas depois recebem-nas automaticamente (SchoolService::create).
     */
    public function run(): void
    {
        $service = app(ActivityTypeService::class);

        School::query()->each(fn (School $school) => $service->seedDefaultsForSchool($school));
    }
}
