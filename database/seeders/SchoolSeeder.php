<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class SchoolSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        \App\Models\Schools\School::firstOrCreate(
            ['acronym' => 'ESPL'],
            [
                'name' => 'Escola Secundária Padre Luís',
                'address' => 'Rua das Escolas, 123',
                'phone' => '210000001',
                'email' => 'espl@sgde.pt',
                'active' => true,
            ]
        );

        \App\Models\Schools\School::firstOrCreate(
            ['acronym' => 'EB23S'],
            [
                'name' => 'Escola Básica 2,3 de São João',
                'address' => 'Avenida Central, 45',
                'phone' => '210000002',
                'email' => 'eb23s@sgde.pt',
                'active' => true,
            ]
        );
    }
}
