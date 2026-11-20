<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Semeia a base de dados com a configuração completa e realista para a apresentação prática.
     */
    public function run(): void
    {
        $this->call([
            // 1. Permissões e Perfis
            RoleSeeder::class,

            // 2. Utilizadores (Administrador Miguel Silva e Staff Ana Costa)
            UserSeeder::class,

            // 3. Escolas fictícias (EB1 de Vale Verde e JI de Vale Verde)
            SchoolSeeder::class,

            // 4. Feriados Nacionais (2025 e 2026; 2027 fica por importar para a live demo)
            HolidaySeeder::class,

            // 5. Catálogo de Tipos de Ausência / Falta
            AbsenceTypeSeeder::class,

            // 6. Atividades diferenciadas por escola (sem Apoio NEE e sem Apoio Turmas/escola)
            ActivityTypeSeeder::class,

            // 7. Horário de funcionamento das escolas (segunda a sexta)
            SchoolOperatingRuleSeeder::class,

            // 8. Assistentes com dados completos, registo criminal e transferência ativa
            AssistantSeeder::class,

            // 9. Horários dos assistentes com 7h de trabalho + 1h de almoço
            AssistantScheduleProfileSeeder::class,

            // 10. Regras de cobertura por escola
            ScheduleRuleSeeder::class,

            // 11. Histórico de faltas (desde setembro de 2025 até final de novembro de 2026)
            AbsenceHistorySeeder::class,

            // 12. Histórico de escalas publicadas (desde setembro de 2025) e rascunho de Dezembro 2026
            ScheduleHistorySeeder::class,
        ]);
    }
}
