<?php

namespace Database\Seeders;

use App\Models\Schools\School;
use Illuminate\Database\Seeder;

class SchoolSeeder extends Seeder
{
    /**
     * Escolas fictícias criadas para a demonstração prática (privacidade preservada).
     *
     *  - EB1VV → EB1 de Vale Verde (1.º ciclo)
     *  - JIVV  → JI de Vale Verde (pré-escolar)
     */
    public function run(): void
    {
        School::updateOrCreate(
            ['acronym' => 'EB1VV'],
            [
                'name' => 'EB1 de Vale Verde',
                'address' => 'Rua do Vale Verde, 14',
                'phone' => '219123451',
                'email' => 'eb1.valeverde@sgde.pt',
                'active' => true,
            ]
        );

        School::updateOrCreate(
            ['acronym' => 'JIVV'],
            [
                'name' => 'JI de Vale Verde',
                'address' => 'Largo das Acácias, 8',
                'phone' => '219123452',
                'email' => 'ji.valeverde@sgde.pt',
                'active' => true,
            ]
        );
    }
}
