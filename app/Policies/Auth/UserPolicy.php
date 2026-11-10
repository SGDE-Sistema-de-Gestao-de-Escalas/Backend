<?php

namespace App\Policies\Auth;

use App\Models\Auth\User;
use Illuminate\Auth\Access\Response;

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

    public function update(User $actor, User $target): Response
    {
        if ($actor->isAdmin() || $actor->is($target)) {
            return Response::allow();
        }

        return Response::deny('Não tem permissão para alterar dados de outros utilizadores.');
    }

    public function delete(User $actor, User $target): Response
    {
        if (! $actor->isAdmin()) {
            if ($actor->is($target)) {
                return Response::deny('Os assistentes não se podem auto-eliminar diretamente.');
            }

            return Response::deny('Não tem permissão para eliminar este utilizador.');
        }

        return Response::allow();
    }

    public function deactivate(User $actor, User $target): Response
    {
        if (! $actor->isAdmin()) {
            return Response::deny('Não tem permissão para desativar este utilizador.');
        }

        if ($actor->is($target)) {
            return Response::deny('Não pode desativar a sua própria conta.');
        }

        return Response::allow();
    }

    public function forceDelete(User $actor, User $target): bool
    {
        return $actor->isAdmin();
    }
    
}