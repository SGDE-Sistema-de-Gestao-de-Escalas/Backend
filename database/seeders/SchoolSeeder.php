<?php

namespace Database\Seeders;

use App\Models\Schools\School;
use Illuminate\Database\Seeder;

class SchoolSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        School::updateOrCreate(
            ['acronym' => 'ESPL'],
            [
                'name' => 'EB1 Miosótis',
                'address' => 'Rua das Escolas, 12',
                'phone' => '219123451',
                'email' => 'eb1.miosotis@sgde.pt',
                'active' => true,
            ]
        );

        School::updateOrCreate(
            ['acronym' => 'EB23S'],
            [
                'name' => 'JI Miosótis',
                'address' => 'Avenida das Oliveiras, 45',
                'phone' => '219123452',
                'email' => 'ji.miosotis@sgde.pt',
                'active' => true,
            ]
        );
    }
}
