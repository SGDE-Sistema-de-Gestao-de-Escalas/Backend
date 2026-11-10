<?php

namespace App\Policies\Schools;

use App\Models\Auth\User;
use App\Models\Schools\SchoolOperatingRule;
use App\Policies\Concerns\HandlesSchoolScopedPolicy;

class SchoolOperatingRulePolicy
{
    use HandlesSchoolScopedPolicy;

    /**
     * Administrador: qualquer versão. Assistente: só as versões de uma escola a
     * que tem acesso (a sua escola ou uma cedência temporária em vigor).
     */
    public function view(User $user, SchoolOperatingRule $schoolOperatingRule): bool
    {
        return $this->canViewSchoolResource($user, $schoolOperatingRule->school_id);
    }
}
