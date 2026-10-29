<?php

namespace App\Policies\System;

use App\Models\Auth\User;
use App\Models\System\ActivityType;
use App\Services\SchoolAccessService;

class ActivityTypePolicy
{
    public function __construct(
        private readonly SchoolAccessService $schoolAccess
    ) {}

    public function viewAny(User $user): bool
    {
        return $user->isAdmin() || $user->isStaff();
    }

    /**
     * Administrador: qualquer atividade. Assistente: só as de uma escola a que
     * tem acesso (a sua ou uma cedência temporária em vigor) (F-09).
     */
    public function view(User $user, ActivityType $activityType): bool
    {
        if ($user->isAdmin()) {
            return true;
        }

        return $user->isStaff()
            && $this->schoolAccess->canAccess($user, $activityType->school_id);
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
