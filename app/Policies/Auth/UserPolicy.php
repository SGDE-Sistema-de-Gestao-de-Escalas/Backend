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

    public function delete(User $actor, User $target): bool
    {
        return $actor->isAdmin() && ! $actor->is($target);
    }

    public function forceDelete(User $actor, User $target): bool
    {
        return $actor->is_active
            && $this->delete($actor, $target);
    }
    
}