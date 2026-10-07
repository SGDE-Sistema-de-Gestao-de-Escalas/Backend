<?php

namespace App\Services;

use App\Models\Auth\User;
use Illuminate\Support\Str;
use Illuminate\Support\Arr;
use App\Models\Auth\Role;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use App\Models\Assistants\Assistant;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

class UserService
{
    public function create(array $data): User
    {   
        $adminRole = Role::where('slug', 'admin')->firstOrFail();

        $user = User::create([
            'first_name' => $data['first_name'],
            'last_name' => $data['last_name'],
            'email' => $data['email'],
            'role_id' => $adminRole->id,
            'password' => Str::random(64),
        ]);

        return $user->refresh()->load('role');
    }

    public function update(User $user, array $data): User
    {
        return DB::transaction(function () use ($user, $data) {
            $adminRole = Role::where('slug', 'admin')
                ->lockForUpdate()
                ->firstOrFail();

            $user = User::whereKey($user->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            if ($user->anonymized_at !== null) {
                throw ValidationException::withMessages([
                    'user' => ['Não é possível alterar ou reativar uma conta anonimizada.'],
                ]);
            }

            $user->fill(
                Arr::only($data, ['first_name', 'last_name', 'email', 'role_id'])
            );

            $wasAdmin = (string) $user->getOriginal('role_id')
                === (string) $adminRole->id;

            if ($wasAdmin && $user->is_active && $user->isDirty('role_id'))
            {
                $activeAdmins = User::where('role_id', $adminRole->id)
                    ->where('is_active', true)
                    ->lockForUpdate()
                    ->get(['id']);

                if ($activeAdmins->count() <= 1) {
                    throw ValidationException::withMessages([
                        'role_id' => [
                            'Não é possível alterar o perfil do último administrador ativo.',
                        ],
                    ]);
                }
            }

            if ($user->isDirty('email')) {
                $user->email_verified_at = null;
            }

            if (array_key_exists('is_active', $data)) {
                $user->is_active = true;
            }

            $user->save();

            return $user->load('role');
        });
    }

    public function deactivate(User $actor, User $target): void
    {
        DB::transaction(function () use ($actor, $target) {
            $adminRole = Role::where('slug', 'admin')
                ->lockForUpdate()
                ->firstOrFail();

            $actor = User::whereKey($actor->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            $target = User::whereKey($target->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            abort_unless($actor->is_active, 403);

            Gate::forUser($actor)->authorize('delete', $target);

            if (
                $target->is_active
                && (string) $target->role_id === (string) $adminRole->id
            ) {
                $activeAdmins = User::where('role_id', $adminRole->id)
                    ->where('is_active', true)
                    ->lockForUpdate()
                    ->get(['id']);

                if ($activeAdmins->count() <= 1) {
                    throw ValidationException::withMessages([
                        'user' => [
                            'Não é possível desativar o último administrador ativo.',
                        ],
                    ]);
                }
            }

            $target->is_active = false;
            $target->save();

            $target->tokens()->delete();
        });
    }

    public function anonymize(User $actor, User $target): void
    {
        DB::transaction(function () use ($actor, $target) {
            $this->deactivate($actor, $target);

            $target->refresh();

            $assistants = Assistant::withTrashed()
                ->where('user_id', $target->id)
                ->lockForUpdate()
                ->get();

            foreach ($assistants as $assistant) {
                $criminalRecordPath = $assistant->criminal_record_path;
                $assistantId = $assistant->id;

                if (array_key_exists('first_name', $assistant->getAttributes())) {
                    $assistant->first_name = 'Assistente';
                }

                if (array_key_exists('last_name', $assistant->getAttributes())) {
                    $assistant->last_name = 'Anonimizado';
                }

                if (array_key_exists('email', $assistant->getAttributes())) {
                    $assistant->email = null;
                }
                $assistant->phone = null;
                $assistant->nif = null;
                $assistant->social_security_number = null;
                $assistant->birth_date = null;
                $assistant->address_street = null;
                $assistant->address_zip_code = null;
                $assistant->emergency_contact_name = null;
                $assistant->emergency_contact_phone = null;
                $assistant->emergency_contact_kinship = null;
                $assistant->available_for_transfer = false;
                $assistant->criminal_record_expiry = null;
                 
                $assistant->save();

                if (! $assistant->trashed()) {
                    $assistant->delete();
                }

                if ($criminalRecordPath !== null && $criminalRecordPath !== '') {
                    DB::afterCommit(function () use ($criminalRecordPath, $assistantId) {
                        if (! Storage::disk('local')->delete($criminalRecordPath)) {
                            throw new RuntimeException(
                                'Não foi possível remover o ficheiro do registo criminal.'
                            );
                        }

                        Assistant::withTrashed()
                            ->whereKey($assistantId)
                            ->where('criminal_record_path', $criminalRecordPath)
                            ->update(['criminal_record_path' => null]);
                    });
                }
            }


            $originalEmail = $target->email;

            $target->first_name = 'Utilizador';
            $target->last_name = 'Anonimizado';
            $target->email = 'anon_' . $target->id . '@deleted.local';
            $target->email_verified_at = null;
            $target->password = Str::random(64);
            $target->provider = null;
            $target->provider_id = null;
            $target->remember_token = null;
            $target->anonymized_at = now();

            $target->save();

            DB::table(config('auth.passwords.users.table'))
                ->where('email', $originalEmail)
                ->delete();

            if (config('session.driver') === 'database') {
                DB::connection(config('session.connection'))
                    ->table(config('session.table'))
                    ->where('user_id', $target->id)
                    ->delete();
            }

            $target->notifications()->update([
                'type' => 'anonymized',
                'title' => 'Notificação anonimizada',
                'body' => null,
                'data' => null,
            ]);
            
            $target->delete();
        });
    }
    
    public function delete(User $actor, User $target): string
    {
        return DB::transaction(function () use ($actor, $target) {
            $this->deactivate($actor, $target);

            $target->refresh();

            if ($this->deleteAction($target) === 'anonymize') {
                $this->anonymize($actor, $target);

                return 'anonymize';
            }

            Gate::forUser($actor)->authorize('forceDelete', $target);

            DB::table(config('auth.passwords.users.table'))
                ->where('email', $target->email)
                ->delete();

            if (config('session.driver') === 'database') {
                DB::connection(config('session.connection'))
                    ->table(config('session.table'))
                    ->where('user_id', $target->id)
                    ->delete();
            }

            $target->forceDelete();

            return 'hard_delete';
        });
    }

    public function deleteAction(User $target): string
    {
        return $this->hasHistory($target)
            ? 'anonymize'
            : 'hard_delete';
    }

    private function hasHistory(User $target): bool
    {
        return Assistant::withTrashed()
            ->where('user_id', $target->id)
            ->exists()
            || DB::table('absences')
                ->where('created_by_user_id', $target->id)
                ->orWhere('justified_by_user_id', $target->id)
                ->exists()
            || DB::table('notifications')
                ->where('user_id', $target->id)
                ->exists()
            || DB::table('assistant_temporary_assignments')
                ->where('created_by_user_id', $target->id)
                ->orWhere('validated_by_user_id', $target->id)
                ->exists();
    }

    public function activeAdminCount(): int
    {
        return User::query()
            ->where('is_active', true)
            ->whereHas('role', function ($query) {
                $query->where('slug', 'admin');
            })
            ->count();
    }  

    public function cannotDeleteReason(
        ?User $actor,
        User $target,
        int $activeAdminCount
    ): ?string {
        if ($actor === null || ! $actor->is_active) {
            return 'Não tem permissão para eliminar esta conta.';
        }

        if ($actor->is($target)) {
            return 'Não pode eliminar a sua própria conta.';
        }

        if (Gate::forUser($actor)->denies('delete', $target)) {
            return 'Não tem permissão para eliminar esta conta.';
        }

        if (
            $target->is_active
            && $target->isAdmin()
            && $activeAdminCount <= 1
        ) {
            return 'Não é possível eliminar o último administrador ativo.';
        }

        return null;
    }
}
