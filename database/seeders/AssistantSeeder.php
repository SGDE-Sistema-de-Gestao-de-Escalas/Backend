<?php

namespace Database\Seeders;

use App\Models\Assistants\Assistant;
use App\Models\Auth\Role;
use App\Models\Auth\User;
use App\Models\Schools\School;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class AssistantSeeder extends Seeder
{
    /**
     * internal_number => [
     *     school_acronym, first_name, last_name, email, phone, nif, niss,
     *     birth_date, admission_date, street, zip, city,
     *     contact_name, contact_phone, contact_kinship
     * ]
     */
    public const ROSTER = [
        // 1.º ciclo (EB1 Miosótis - ESPL)
        'ME-2001' => [
            'ESPL', 'Patrícia', 'Neves', 'patricia.neves@sgde.pt',
            '912345001', '250123406', '12001234501',
            '1982-04-15', '2018-09-01', 'Rua dos Cravos, 14', '2710-101', 'Sintra',
            'António Neves', '961234001', 'Cônjuge',
        ],
        'ME-2002' => [
            'ESPL', 'Anabela', 'Ferreira', 'anabela.ferreira@sgde.pt',
            '912345002', '250123413', '12001234502',
            '1979-11-23', '2016-01-15', 'Avenida Central, 88', '2710-102', 'Sintra',
            'Manuel Ferreira', '961234002', 'Cônjuge',
        ],
        'ME-2003' => [
            'ESPL', 'Joana', 'Antunes', 'joana.antunes@sgde.pt',
            '912345003', '250123420', '12001234503',
            '1990-07-08', '2021-09-01', 'Rua D. Afonso Henriques, 5', '2710-103', 'Sintra',
            'Teresa Antunes', '961234003', 'Mãe',
        ],
        'ME-2004' => [
            'ESPL', 'Maria João', 'Bastos', 'maria.bastos@sgde.pt',
            '912345004', '250123438', '12001234504',
            '1985-03-30', '2019-02-01', 'Praceta das Flores, 12', '2710-104', 'Sintra',
            'Rui Bastos', '961234004', 'Irmão',
        ],
        'ME-2005' => [
            'ESPL', 'Marta', 'Rodrigues', 'marta.rodrigues@sgde.pt',
            '912345005', '250123445', '12001234505',
            '1993-12-19', '2022-09-01', 'Rua Camilo Castelo Branco, 3', '2710-105', 'Sintra',
            'Helena Rodrigues', '961234005', 'Mãe',
        ],
        'ME-2006' => [
            'ESPL', 'Sandra', 'Carvalho', 'sandra.carvalho@sgde.pt',
            '912345006', '250123452', '12001234506',
            '1987-08-14', '2020-10-01', 'Calçada de São Pedro, 22', '2710-106', 'Sintra',
            'Pedro Carvalho', '961234006', 'Cônjuge',
        ],

        // Pré-escolar (JI Miosótis - EB23S)
        'ME-3001' => [
            'EB23S', 'Jacinta', 'Pires', 'jacinta.pires@sgde.pt',
            '912345007', '250123460', '12001234507',
            '1981-05-25', '2017-09-01', 'Rua das Oliveiras, 40', '2710-201', 'Sintra',
            'Joaquim Pires', '961234007', 'Pai',
        ],
        'ME-3002' => [
            'EB23S', 'Carla', 'Moreira', 'carla.moreira@sgde.pt',
            '912345008', '250123477', '12001234508',
            '1988-09-12', '2019-11-01', 'Alameda dos Oceanos, 19', '2710-202', 'Sintra',
            'Luís Moreira', '961234008', 'Cônjuge',
        ],
        'ME-3003' => [
            'EB23S', 'Filipa', 'Santos', 'filipa.santos@sgde.pt',
            '912345009', '250123484', '12001234509',
            '1994-02-17', '2023-01-09', 'Rua Serpa Pinto, 8', '2710-203', 'Sintra',
            'Maria Santos', '961234009', 'Mãe',
        ],
        'ME-3004' => [
            'EB23S', 'Diana', 'Ribeiro', 'diana.ribeiro@sgde.pt',
            '912345010', '250123491', '12001234510',
            '1986-10-05', '2018-03-01', 'Estrada de Chão de Meninos, 55', '2710-204', 'Sintra',
            'Fernando Ribeiro', '961234010', 'Cônjuge',
        ],
    ];

    public function run(): void
    {
        $staffRole = Role::where('slug', 'staff')->first();
        if (! $staffRole) {
            $this->call(RoleSeeder::class);
            $staffRole = Role::where('slug', 'staff')->first();
        }

        if (! School::query()->whereIn('acronym', ['ESPL', 'EB23S'])->exists()) {
            $this->call(SchoolSeeder::class);
        }

        $schools = School::query()->pluck('id', 'acronym');

        if (! $staffRole || ! isset($schools['ESPL'], $schools['EB23S'])) {
            $this->command?->error('Faltam a Role "staff" ou as escolas ESPL / EB23S. Corre primeiro o RoleSeeder e o SchoolSeeder.');
            return;
        }

        $defaultPassword = Hash::make((string) (config('app.seed_staff_password') ?: 'staff123'));

        foreach (self::ROSTER as $internalNumber => $data) {
            [
                $acronym, $firstName, $lastName, $email,
                $phone, $nif, $niss,
                $birthDate, $admissionDate, $street, $zip, $city,
                $contactName, $contactPhone, $contactKinship,
            ] = $data;

            $user = User::firstOrCreate(
                ['email' => $email],
                [
                    'first_name' => $firstName,
                    'last_name' => $lastName,
                    'password' => $defaultPassword,
                    'role_id' => $staffRole->id,
                    'is_active' => true,
                    'has_password' => true,
                ]
            );

            Assistant::withTrashed()->updateOrCreate(
                ['internal_number' => $internalNumber],
                [
                    'user_id' => $user->id,
                    'school_id' => $schools[$acronym],
                    'phone' => $phone,
                    'nif' => $nif,
                    'social_security_number' => $niss,
                    'birth_date' => $birthDate,
                    'admission_date' => $admissionDate,
                    'address_street' => $street,
                    'address_zip_code' => $zip,
                    'address_city' => $city,
                    'emergency_contact_name' => $contactName,
                    'emergency_contact_phone' => $contactPhone,
                    'emergency_contact_kinship' => $contactKinship,
                    'criminal_record_name' => 'registo_criminal_valido.pdf',
                    'criminal_record_path' => "criminal_records/{$internalNumber}_rc.pdf",
                    'criminal_record_expiry' => '2027-12-31',
                    'available_for_transfer' => true,
                    'is_anonymized' => false,
                ]
            );
        }
    }
}
