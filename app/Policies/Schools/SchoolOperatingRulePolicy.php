<?php

namespace App\Policies\Schools;

use App\Models\Auth\User;
use App\Models\Schools\SchoolOperatingRule;
use App\Services\SchoolAccessService;

class SchoolOperatingRulePolicy
{
    public function __construct(
        private readonly SchoolAccessService $schoolAccess
    ) {}

    /**
     * Administradores e assistentes podem listar. O controller restringe o
     * resultado à escola do cabeçalho X-School-ID (já validada no acesso).
     */
    public function viewAny(User $user): bool
    {
        return $user->isAdmin() || $user->isStaff();
    }

    /**
     * Administrador: qualquer versão. Assistente: só as versões de uma escola a
     * que tem acesso (a sua escola ou uma cedência temporária em vigor).
     */
    public function view(User $user, SchoolOperatingRule $schoolOperatingRule): bool
    {
        if ($user->isAdmin()) {
            return true;
        }

        return $user->isStaff()
            && $this->schoolAccess->canAccess($user, $schoolOperatingRule->school_id);
    }

    public function create(User $user): bool
    {
        return $user->isAdmin();
    }

    public function update(User $user, SchoolOperatingRule $schoolOperatingRule): bool
    {
        return $user->isAdmin();
    }

    public function delete(User $user, SchoolOperatingRule $schoolOperatingRule): bool
    {
        return $user->isAdmin();
    }

    public function restore(User $user, SchoolOperatingRule $schoolOperatingRule): bool
    {
        return $user->isAdmin();
    }

    public function forceDelete(User $user, SchoolOperatingRule $schoolOperatingRule): bool
    {
        return $user->isAdmin();
    }
}
