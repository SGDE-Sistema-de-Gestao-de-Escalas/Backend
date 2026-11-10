<?php

namespace App\Policies\Schools;

use App\Models\Auth\User;
use App\Models\Schools\SchoolOperatingException;
use App\Policies\Concerns\HandlesSchoolScopedPolicy;

class SchoolOperatingExceptionPolicy
{
    use HandlesSchoolScopedPolicy;

    /**
     * Administrador: qualquer exceção. Assistente: só as de uma escola a que
     * tem acesso (a sua escola ou uma cedência temporária em vigor).
     */
    public function view(User $user, SchoolOperatingException $exception): bool
    {
        return $this->canViewSchoolResource($user, $exception->school_id);
    }
}
