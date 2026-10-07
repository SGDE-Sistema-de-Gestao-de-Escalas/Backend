<?php

namespace App\Policies\Auth;

use App\Models\Auth\User;

class UserPolicy
{
    public function viewAny(User $actor): bool
    {
        return $actor->isAdmin();
    }

    public function view(User $actor, User $target): bool
    {
        return $actor->isAdmin();
    }

    public function create(User $actor): bool
    {
        return $actor->isAdmin();
    }

    public function update(User $actor, User $target): \Illuminate\Auth\Access\Response
    {
        if ($actor->isAdmin() || $actor->is($target)) {
            return \Illuminate\Auth\Access\Response::allow();
        }

        return \Illuminate\Auth\Access\Response::deny('Não tem permissão para alterar dados de outros utilizadores.');
    }

    public function delete(User $actor, User $target): \Illuminate\Auth\Access\Response
    {
        if (! $actor->isAdmin()) {
            if ($actor->is($target)) {
                return \Illuminate\Auth\Access\Response::deny('Os assistentes não se podem auto-eliminar diretamente.');
            }

            return \Illuminate\Auth\Access\Response::deny('Não tem permissão para eliminar este utilizador.');
        }

        return \Illuminate\Auth\Access\Response::allow();
    }

    public function deactivate(User $actor, User $target): \Illuminate\Auth\Access\Response
    {
        if (! $actor->isAdmin()) {
            return \Illuminate\Auth\Access\Response::deny('Não tem permissão para desativar este utilizador.');
        }

        if ($actor->is($target)) {
            return \Illuminate\Auth\Access\Response::deny('Não pode desativar a sua própria conta.');
        }

        return \Illuminate\Auth\Access\Response::allow();
    }

    public function forceDelete(User $actor, User $target): bool
    {
        return $actor->isAdmin();
    }
    
}