<?php

namespace Database\Seeders;

use App\Models\Assistants\Assistant;
use App\Models\Auth\Role;
use App\Models\Auth\User;
use App\Models\Schools\School;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

/**
 * Assistentes reais das duas escolas (folha "Escalas").
 *
 *  - ESPL  → 1.º ciclo
 *  - EB23S → pré-escolar
 *
 * Os horários de cada um estão em AssistantScheduleProfileSeeder (ligados
 * pelo número mecanográfico). Os nomes da folha são só primeiros nomes, por
 * isso o apelido é provisório ("Assistente") até haver os reais.
 *
 * Pode correr várias vezes: atualiza pelo número mecanográfico, não duplica.
 * Não apaga assistentes que já existam na base de dados.
 */
class AssistantSeeder extends Seeder
{
    /** Apelido provisório enquanto não houver os reais. */
    private const PLACEHOLDER_LAST_NAME = 'Assistente';

    /**
     * internal_number => [escola, primeiro nome, email]
     *
     * @var array<string, array{0: string, 1: string, 2: string}>
     */
    public const ROSTER = [
        // 1.º ciclo (ESPL)
        'ME-2001' => ['ESPL', 'Patrícia', 'patricia@sgde.pt'],
        'ME-2002' => ['ESPL', 'Anabela', 'anabela@sgde.pt'],
        'ME-2003' => ['ESPL', 'Joana', 'joana@sgde.pt'],
        'ME-2004' => ['ESPL', 'MJB', 'mjb@sgde.pt'],
        'ME-2005' => ['ESPL', 'Marta', 'marta@sgde.pt'],
        'ME-2006' => ['ESPL', 'Sandra', 'sandra@sgde.pt'],

        // Pré-escolar (EB23S)
        'ME-3001' => ['EB23S', 'Jacinta', 'jacinta@sgde.pt'],
        'ME-3002' => ['EB23S', 'Carla', 'carla@sgde.pt'],
        'ME-3003' => ['EB23S', 'Filipa', 'filipa@sgde.pt'],
        'ME-3004' => ['EB23S', 'Diana', 'diana@sgde.pt'],
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

        $password = Hash::make(env('SEED_STAFF_PASSWORD') ?: 'staff123');

        foreach (self::ROSTER as $internalNumber => [$acronym, $firstName, $email]) {
            $user = User::firstOrCreate(
                ['email' => $email],
                [
                    'first_name' => $firstName,
                    'last_name' => self::PLACEHOLDER_LAST_NAME,
                    'password' => $password,
                    'role_id' => $staffRole->id,
                    'is_active' => true,
                ]
            );

            Assistant::withTrashed()->firstOrCreate(
                ['internal_number' => $internalNumber],
                [
                    'user_id' => $user->id,
                    'school_id' => $schools[$acronym],
                    'available_for_transfer' => false,
                    'is_anonymized' => false,
                ]
            );
        }
    }
}
