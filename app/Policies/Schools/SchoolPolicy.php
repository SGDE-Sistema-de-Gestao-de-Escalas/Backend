<?php

namespace App\Policies\Schools;

use App\Models\Schools\School;
use App\Models\Auth\User;
use App\Services\SchoolAccessService;
use Illuminate\Auth\Access\Response;

class SchoolPolicy
{
    public function __construct(
        private readonly SchoolAccessService $schoolAccess
    ) {}

    /**
     * Determine whether the user can view any models.
     */
    public function viewAny(User $user): bool
    {
        return $user->isAdmin() || $user->isStaff();
    }

    /**
     * Administrador: qualquer escola. Assistente: só a sua escola ou aquela para
     * onde tem uma cedência temporária em vigor (F-09).
     */
    public function view(User $user, School $school): bool
    {
        if ($user->isAdmin()) {
            return true;
        }

        return $user->isStaff()
            && $this->schoolAccess->canAccess($user, $school->id);
    }

    /**
     * Determine whether the user can create models.
     */
    public function create(User $user): bool
    {
        return $user->isAdmin();
    }

    /**
     * Determine whether the user can update the model.
     */
    public function update(User $user, School $school): bool
    {
        return $user->isAdmin();
    }

    /**
     * Determine whether the user can delete the model.
     */
    public function delete(User $user, School $school): bool
    {
        return $user->isAdmin();
    }

    /**
     * Determine whether the user can restore the model.
     */
    public function restore(User $user, School $school): bool
    {
        return $user->isAdmin();
    }

    /**
     * Determine whether the user can permanently delete the model.
     */
    public function forceDelete(User $user, School $school): bool
    {
        return $user->isAdmin();
    }
}
