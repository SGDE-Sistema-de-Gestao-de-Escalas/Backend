<?php

namespace App\Policies\System;

use App\Models\Auth\User;
use App\Models\System\ActivityType;
use App\Policies\Concerns\HandlesSchoolScopedPolicy;

class ActivityTypePolicy
{
    use HandlesSchoolScopedPolicy;

    /**
     * Administrador: qualquer atividade. Assistente: só as de uma escola a que
     * tem acesso (a sua ou uma cedência temporária em vigor) (F-09).
     */
    public function view(User $user, ActivityType $activityType): bool
    {
        return $this->canViewSchoolResource($user, $activityType->school_id);
    }
}
