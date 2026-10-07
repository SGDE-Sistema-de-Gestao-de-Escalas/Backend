<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class AssistantSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $staffRole = \App\Models\Auth\Role::where('slug', 'staff')->first();
        $school = \App\Models\Schools\School::where('acronym', 'ESPL')->first()
            ?? \App\Models\Schools\School::first();

        if (! $staffRole || ! $school) {
            return;
        }

        // 1. Assistente Ativo Normal (para testar bloqueio 'deve ser inativado previamente')
        $user1 = \App\Models\Auth\User::firstOrCreate(
            ['email' => 'maria.santos@sgde.pt'],
            [
                'first_name' => 'Maria',
                'last_name' => 'Santos',
                'password' => \Illuminate\Support\Facades\Hash::make('staff123'),
                'role_id' => $staffRole->id,
                'is_active' => true,
            ]
        );

        \App\Models\Assistants\Assistant::firstOrCreate(
            ['internal_number' => 'ME-1001'],
            [
                'user_id' => $user1->id,
                'school_id' => $school->id,
                'phone' => '+351 912 345 601',
                'nif' => '234 567 801',
                'social_security_number' => '12345678901',
                'birth_date' => '1992-03-15',
                'admission_date' => '2022-09-01',
                'address_street' => 'Rua das Flores, 12',
                'address_zip_code' => '4000-001',
                'address_city' => 'Porto',
                'emergency_contact_name' => 'Carlos Santos',
                'emergency_contact_phone' => '+351 910 000 001',
                'emergency_contact_kinship' => 'Cônjuge',
                'available_for_transfer' => true,
                'is_anonymized' => false,
            ]
        );

        // 2. Assistente Inativo / Arquivado (Elegível para Anonimização)
        $user2 = \App\Models\Auth\User::firstOrCreate(
            ['email' => 'joao.pereira@sgde.pt'],
            [
                'first_name' => 'João',
                'last_name' => 'Pereira',
                'password' => \Illuminate\Support\Facades\Hash::make('staff123'),
                'role_id' => $staffRole->id,
                'is_active' => false,
            ]
        );

        $assistant2 = \App\Models\Assistants\Assistant::withTrashed()->firstOrCreate(
            ['internal_number' => 'ME-1002'],
            [
                'user_id' => $user2->id,
                'school_id' => $school->id,
                'phone' => '+351 912 345 602',
                'nif' => '234 567 802',
                'social_security_number' => '12345678902',
                'birth_date' => '1988-07-22',
                'admission_date' => '2021-01-15',
                'address_street' => 'Avenida Central, 80',
                'address_zip_code' => '4100-100',
                'address_city' => 'Porto',
                'emergency_contact_name' => 'Teresa Pereira',
                'emergency_contact_phone' => '+351 910 000 002',
                'emergency_contact_kinship' => 'Irmã',
                'available_for_transfer' => false,
                'is_anonymized' => false,
            ]
        );

        // Se o assistente 2 ainda não estiver em soft-delete, coloca-o
        if (! $assistant2->trashed()) {
            $assistant2->delete();
        }

        // 3. Assistente Ativo Disponível para transferências
        $user3 = \App\Models\Auth\User::firstOrCreate(
            ['email' => 'antonio.silva@sgde.pt'],
            [
                'first_name' => 'António',
                'last_name' => 'Silva',
                'password' => \Illuminate\Support\Facades\Hash::make('staff123'),
                'role_id' => $staffRole->id,
                'is_active' => true,
            ]
        );

        \App\Models\Assistants\Assistant::firstOrCreate(
            ['internal_number' => 'ME-1003'],
            [
                'user_id' => $user3->id,
                'school_id' => $school->id,
                'phone' => '+351 912 345 603',
                'nif' => '234 567 803',
                'social_security_number' => '12345678903',
                'birth_date' => '1995-11-30',
                'admission_date' => '2023-03-01',
                'address_street' => 'Praceta da Liberdade, 5',
                'address_zip_code' => '4200-200',
                'address_city' => 'Porto',
                'available_for_transfer' => true,
                'is_anonymized' => false,
            ]
        );
    }
}
