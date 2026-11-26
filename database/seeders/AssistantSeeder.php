<?php

namespace Database\Seeders;

use App\Models\Assistants\Assistant;
use App\Models\Auth\Role;
use App\Models\Auth\User;
use App\Models\Schools\School;
use App\Services\Assistants\AssistantDocumentStorageService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;

class AssistantSeeder extends Seeder
{
    /**
     * Nomes 100% fictícios portugueses para proteção de privacidade.
     *
     * internal_number => [
     *     school_acronym, first_name, last_name, email, phone, nif, niss,
     *     birth_date, admission_date, street, zip, city,
     *     contact_name, contact_phone, contact_kinship
     * ]
     */
    public const ROSTER = [
        // 1.º ciclo (EB1 de Vale Verde - EB1VV)
        'ME-2001' => [
            'EB1VV', 'Beatriz', 'Almeida', 'beatriz.almeida@sgde.pt',
            '912345001', '250123401', '12001234501',
            '1982-04-15', '2018-09-01', 'Rua dos Cravos, 14', '2710-101', 'Sintra',
            'António Almeida', '961234001', 'Cônjuge',
        ],
        'ME-2002' => [
            'EB1VV', 'Cláudia', 'Teixeira', 'claudia.teixeira@sgde.pt',
            '912345002', '250123410', '12001234502',
            '1979-11-23', '2016-01-15', 'Avenida Central, 88', '2710-102', 'Sintra',
            'Manuel Teixeira', '961234002', 'Cônjuge',
        ],
        'ME-2003' => [
            'EB1VV', 'Inês', 'Monteiro', 'ines.monteiro@sgde.pt',
            '912345003', '250123428', '12001234503',
            '1990-07-08', '2021-09-01', 'Rua D. Afonso Henriques, 5', '2710-103', 'Sintra',
            'Rosa Monteiro', '961234003', 'Mãe',
        ],
        'ME-2004' => [
            'EB1VV', 'Sofia', 'Marques', 'sofia.marques@sgde.pt',
            '912345004', '250123436', '12001234504',
            '1985-03-30', '2019-02-01', 'Praceta das Flores, 12', '2710-104', 'Sintra',
            'Rui Marques', '961234004', 'Irmão',
        ],
        'ME-2005' => [
            'EB1VV', 'Raquel', 'Nogueira', 'raquel.nogueira@sgde.pt',
            '912345005', '250123444', '12001234505',
            '1993-12-19', '2022-09-01', 'Rua Camilo Castelo Branco, 3', '2710-105', 'Sintra',
            'Paula Nogueira', '961234005', 'Mãe',
        ],
        'ME-2006' => [
            'EB1VV', 'Helena', 'Cardoso', 'helena.cardoso@sgde.pt',
            '912345006', '250123452', '12001234506',
            '1987-08-14', '2020-10-01', 'Calçada de São Pedro, 22', '2710-106', 'Sintra',
            'Pedro Cardoso', '961234006', 'Cônjuge',
        ],

        // Pré-escolar (JI de Vale Verde - JIVV)
        'ME-3001' => [
            'JIVV', 'Teresa', 'Lopes', 'teresa.lopes@sgde.pt',
            '912345007', '250123460', '12001234507',
            '1981-05-25', '2017-09-01', 'Rua das Oliveiras, 40', '2710-201', 'Sintra',
            'Joaquim Lopes', '961234007', 'Pai',
        ],
        'ME-3002' => [
            'JIVV', 'Mariana', 'Correia', 'mariana.correia@sgde.pt',
            '912345008', '250123479', '12001234508',
            '1988-09-12', '2019-11-01', 'Alameda dos Oceanos, 19', '2710-202', 'Sintra',
            'Luís Correia', '961234008', 'Cônjuge',
        ],
        'ME-3003' => [
            'JIVV', 'Catarina', 'Mendes', 'catarina.mendes@sgde.pt',
            '912345009', '250123487', '12001234509',
            '1994-02-17', '2023-01-09', 'Rua Serpa Pinto, 8', '2710-203', 'Sintra',
            'Maria Mendes', '961234009', 'Mãe',
        ],
        'ME-3004' => [
            'JIVV', 'Lúcia', 'Gonçalves', 'lucia.goncalves@sgde.pt',
            '912345010', '250123495', '12001234510',
            '1986-10-05', '2018-03-01', 'Estrada de Chão de Meninos, 55', '2710-204', 'Sintra',
            'Fernando Gonçalves', '961234010', 'Cônjuge',
        ],
    ];

    public function run(): void
    {
        $staffRole = Role::where('slug', 'staff')->first();
        if (! $staffRole) {
            $this->call(RoleSeeder::class);
            $staffRole = Role::where('slug', 'staff')->first();
        }

        if (! School::query()->whereIn('acronym', ['EB1VV', 'JIVV'])->exists()) {
            $this->call(SchoolSeeder::class);
        }

        $schools = School::query()->pluck('id', 'acronym');

        if (! $staffRole || ! isset($schools['EB1VV'], $schools['JIVV'])) {
            $this->command?->error('Faltam a Role "staff" ou as escolas EB1VV / JIVV. Corre primeiro o RoleSeeder e o SchoolSeeder.');
            return;
        }

        $defaultPassword = Hash::make((string) (config('app.seed_staff_password') ?: 'staff123'));
        $storageService = app(AssistantDocumentStorageService::class);
        $disk = $storageService->getDisk('criminal_record');
        $canWriteLocalPdf = in_array($disk, ['public', 'local'], true);

        foreach (self::ROSTER as $internalNumber => $data) {
            [
                $acronym, $firstName, $lastName, $email,
                $phone, $nif, $niss,
                $birthDate, $admissionDate, $street, $zip, $city,
                $contactName, $contactPhone, $contactKinship,
            ] = $data;

            $user = User::updateOrCreate(
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

            $dir = $storageService->getAssistantDirectory($firstName, $lastName, $internalNumber, 'criminal_record');
            $rcPath = "{$dir}/registo_criminal_valido.pdf";

            if ($canWriteLocalPdf) {
                Storage::disk($disk)->put($rcPath, $this->buildMinimalPdf("Certificado de Registo Criminal - {$firstName} {$lastName} ({$internalNumber}) - Valido ate 2027-12-31"));
            }

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
                    'criminal_record_path' => $rcPath,
                    'criminal_record_expiry' => '2027-12-31',
                    'available_for_transfer' => true,
                    'is_anonymized' => false,
                ]
            );
        }
    }

    private function buildMinimalPdf(string $text): string
    {
        $safe = preg_replace('/[^\x20-\x7E]/', '', iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $text) ?: $text);
        $safe = str_replace(['\\', '(', ')'], ['\\\\', '\\(', '\\)'], (string) $safe);
        $stream = "BT /F1 12 Tf 50 750 Td ({$safe}) Tj ET";
        $len = strlen($stream);

        return "%PDF-1.4\n"
            ."1 0 obj << /Type /Catalog /Pages 2 0 R >> endobj\n"
            ."2 0 obj << /Type /Pages /Kids [3 0 R] /Count 1 >> endobj\n"
            ."3 0 obj << /Type /Page /Parent 2 0 R /MediaBox [0 0 595 842] /Contents 4 0 R /Resources << /Font << /F1 5 0 R >> >> >> endobj\n"
            ."4 0 obj << /Length {$len} >> stream\n{$stream}\nendstream endobj\n"
            ."5 0 obj << /Type /Font /Subtype /Type1 /BaseFont /Helvetica >> endobj\n"
            ."xref\n0 6\n0000000000 65535 f \n0000000009 00000 n \n0000000058 00000 n \n0000000115 00000 n \n0000000241 00000 n \n0000000390 00000 n \n"
            ."trailer << /Size 6 /Root 1 0 R >>\nstartxref\n460\n%%EOF\n";
    }
}
