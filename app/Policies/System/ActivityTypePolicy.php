<?php

namespace App\Policies\System;

use App\Models\Auth\User;
use App\Models\System\ActivityType;

class ActivityTypePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->isAdmin();
    }

    public function view(User $user, ActivityType $activityType): bool
    {
        return $user->isAdmin();
    }

    public function create(User $user): bool
    {
        return $user->isAdmin();
    }

    public function update(User $user, ActivityType $activityType): bool
    {
        return $user->isAdmin();
    }

    public function delete(User $user, ActivityType $activityType): bool
    {
        return $user->isAdmin();
    }

    public function restore(User $user, ActivityType $activityType): bool
    {
        return $user->isAdmin();
    }

    public function forceDelete(User $user, ActivityType $activityType): bool
    {
        return $user->isAdmin();
    }
}
