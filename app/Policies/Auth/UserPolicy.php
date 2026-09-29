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

    public function update(User $actor, User $target): bool
    {
        return $actor->isAdmin();
    }

    public function delete(User $actor, User $target): bool
    {
        return $actor->isAdmin() && ! $actor->is($target);
    }
}