<?php

namespace App\Services;

use App\Models\Auth\User;
use Illuminate\Support\Str;
use Illuminate\Support\Arr;
use App\Models\Auth\Role;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class UserService
{
    public function create(array $data): User
    {
        $user = User::create([
            'name' => $data['name'],
            'email' => $data['email'],
            'role_id' => $data['role_id'],
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

            $user->fill(
                Arr::only($data, ['name', 'email', 'role_id'])
            );

            $wasAdmin = (int) $user->getOriginal('role_id')
                === (int) $adminRole->id;

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
                && (int) $target->role_id === (int) $adminRole->id
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
}