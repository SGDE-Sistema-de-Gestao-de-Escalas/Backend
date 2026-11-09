<?php

namespace App\Policies\Schools;

use App\Models\Auth\User;
use App\Models\Schools\School;
use App\Policies\Concerns\HandlesSchoolScopedPolicy;

class SchoolPolicy
{
    use HandlesSchoolScopedPolicy;

    /**
     * Administrador: qualquer escola. Assistente: só a sua escola ou aquela para
     * onde tem uma cedência temporária em vigor (F-09).
     */
    public function view(User $user, School $school): bool
    {
        return $this->canViewSchoolResource($user, $school->id);
    }
}
