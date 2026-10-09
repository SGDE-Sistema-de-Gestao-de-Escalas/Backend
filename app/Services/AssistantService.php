<?php

namespace App\Services;

use App\Models\Assistants\Assistant;
use App\Models\Auth\Role;
use App\Models\Auth\User;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

class AssistantService
{
    /**
     * Campos que pertencem à conta (tabela users).
     * O email é partilhado: fica no user (login) e no assistente (contacto).
     */
    private const USER_FIELDS = ['first_name', 'last_name', 'email'];

    public function __construct(
        private readonly AuthService $authService
    ) {}

    /**
     * Cria o user STAFF e o assistente na mesma transação: ou ficam os dois
     * criados, ou nenhum. O email para definir a password só é enviado
     * depois do commit, para nunca mandar emails de contas que não existem.
     */
    public function create(array $data): Assistant
    {
        $assistant = DB::transaction(function () use ($data) {
            $staffRole = Role::where('slug', 'staff')->firstOrFail();

            $user = User::create([
                'first_name' => $data['first_name'],
                'last_name' => $data['last_name'],
                'email' => $data['email'],
                'role_id' => $staffRole->id,
                // Password inutilizável; o assistente define a sua pelo link do email.
                'password' => Hash::make(Str::random(64)),
            ]);

            $assistantData = Arr::except($data, self::USER_FIELDS);

            $assistant = $user->assistant()->create($assistantData);

            return $assistant;
        });

        $this->sendAccessEmail($assistant->user);

        return $assistant->load(['user', 'school', 'assistantScheduleProfiles.shifts.shiftDays']);
    }

    public function update(Assistant $assistant, array $data): Assistant
    {
        return DB::transaction(function () use ($assistant, $data) {
            $userData = Arr::only($data, self::USER_FIELDS);

            if ($userData !== []) {
                $user = $assistant->user;
                $user->fill($userData);

                if ($user->isDirty('email')) {
                    $user->email_verified_at = null;
                }

                $user->save();
            }

            $assistantData = Arr::except($data, ['first_name', 'last_name']);
            $assistant->update($assistantData);

            return $assistant->refresh()->load(['user', 'school', 'assistantScheduleProfiles.shifts.shiftDays']);
        });
    }

    /**
     * Soft delete (eliminação) do assistente e da respetiva conta,
     * e revoga as sessões abertas para que deixe de ter acesso imediatamente.
     */
    public function delete(Assistant $assistant): void
    {
        DB::transaction(function () use ($assistant) {
            $user = $assistant->user;

            $assistant->delete();

            if ($user && ! $user->trashed()) {
                $user->tokens()->delete();
                $user->update(['is_active' => false]);
                $user->delete();
            }
        });
    }

    /**
     * Verifica se o assistente é elegível para anonimização:
     * - O assistente tem de estar inativo (is_active == false).
     * - O assistente não pode ter turnos futuros (COUNT(*) == 0 onde data >= CURRENT_DATE).
     */
    public function checkCanAnonymize(Assistant $assistant): array
    {
        $assistant->loadMissing('user');
        $isActive = (bool) ($assistant->user?->is_active ?? false) && ! $assistant->trashed();

        $futureSchedulesCount = $this->countFutureSchedules($assistant);
        $hasFutureSchedules = $futureSchedulesCount > 0;

        if ($hasFutureSchedules) {
            return [
                'can_anonymize' => false,
                'has_future_schedules' => true,
                'future_schedules_count' => $futureSchedulesCount,
                'reason' => "O assistente possui {$futureSchedulesCount} turnos atribuídos a partir de hoje. É necessário desatribuir ou substituir o assistente nas escalas antes de anonimizar.",
            ];
        }

        if ($isActive) {
            return [
                'can_anonymize' => false,
                'has_future_schedules' => false,
                'future_schedules_count' => 0,
                'reason' => 'O assistente deve ser inativado previamente antes de poder ser anonimizado.',
            ];
        }

        return [
            'can_anonymize' => true,
            'has_future_schedules' => false,
            'future_schedules_count' => 0,
            'reason' => null,
        ];
    }

    /**
     * Conta escalas/turnos futuros do assistente a partir da data de hoje.
     */
    public function countFutureSchedules(Assistant $assistant): int
    {
        $today = now()->toDateString();

        // 1. Contagem através dos turnos diários (schedule_entries) associados aos horários do assistente
        $futureEntriesCount = DB::table('schedule_entries')
            ->join('schedules', 'schedule_entries.schedule_id', '=', 'schedules.id')
            ->where('schedules.assistant_id', $assistant->id)
            ->where('schedule_entries.date', '>=', $today)
            ->whereNull('schedules.deleted_at')
            ->count();

        // 2. Se não houver entradas individuais, verifica semanas de escalas abertas com término >= hoje
        if ($futureEntriesCount === 0) {
            return DB::table('schedules')
                ->where('assistant_id', $assistant->id)
                ->where(function ($q) use ($today) {
                    $q->where('week_end', '>=', $today)
                      ->orWhere('week_start', '>=', $today);
                })
                ->whereNull('deleted_at')
                ->count();
        }

        return $futureEntriesCount;
    }

    /**
     * Executa a anonimização definitiva (Direito ao Esquecimento RGPD):
     * NÃO FAZ DELETE do registo do assistente nem dos turnos passados.
     */
    public function anonymize(Assistant $assistant): Assistant
    {
        return DB::transaction(function () use ($assistant) {
            $assistant->loadMissing('user');

            // 1. Atualizar dados cadastrais do assistente
            $assistant->internal_number = 'anon_' . $assistant->id;
            $assistant->phone = null;
            $assistant->nif = null;
            $assistant->social_security_number = null;
            $assistant->birth_date = null;
            $assistant->admission_date = null;
            $assistant->address_street = null;
            $assistant->address_zip_code = null;
            $assistant->address_city = null;
            $assistant->emergency_contact_name = null;
            $assistant->emergency_contact_phone = null;
            $assistant->emergency_contact_kinship = null;
            $assistant->available_for_transfer = false;
            $assistant->criminal_record_expiry = null;
            $assistant->is_anonymized = true;

            // Remove documento do registo criminal do disco
            if ($assistant->criminal_record_path) {
                Storage::disk('local')->delete($assistant->criminal_record_path);
                $assistant->criminal_record_path = null;
            }

            $assistant->save();

            // 2. Inativar utilizador e revogar tokens de login
            $user = $assistant->user;
            if ($user) {
                $user->tokens()->delete();

                if (config('session.driver') === 'database') {
                    DB::connection(config('session.connection'))
                        ->table(config('session.table'))
                        ->where('user_id', $user->id)
                        ->delete();
                }

                $user->first_name = 'Assistente';
                $user->last_name = 'Anonimizado';
                $user->email = 'anon_' . $user->id . '@sistema.local';
                $user->is_active = false;
                $user->anonymized_at = now();
                $user->save();
            }

            return $assistant->fresh(['user', 'school']);
        });
    }

    /**
     * Uma falha no envio do email não deve anular a criação do assistente:
     * regista o erro e o admin pode reenviar pelo "esqueci-me da password".
     */
    private function sendAccessEmail(User $user): void
    {
        try {
            $this->authService->sendPasswordResetLink($user->email);
        } catch (Throwable $e) {
            report($e);
        }
    }
}

