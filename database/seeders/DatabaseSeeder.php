<?php

namespace Database\Seeders;

use App\Models\Auth\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        $this->call([
            RoleSeeder::class,
            UserSeeder::class,
            SchoolSeeder::class,
            AbsenceTypeSeeder::class,
            // Depois das escolas: cria as atividades predefinidas em cada uma.
            ActivityTypeSeeder::class,
            // Horário de funcionamento das escolas, depois das escolas.
            SchoolOperatingRuleSeeder::class,
            // Assistentes e respetivos horários (dependem das escolas).
            AssistantSeeder::class,
            AssistantScheduleProfileSeeder::class,
            // Regras de horário por período com assistentes aptos.
            ScheduleRuleSeeder::class,
        ]);
    }
}