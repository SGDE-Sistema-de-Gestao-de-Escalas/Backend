<?php

namespace App\Policies\Schools;

use App\Models\Schools\SchoolOperatingRule;
use App\Models\Auth\User;
use Illuminate\Auth\Access\Response;

class SchoolOperatingRulePolicy
{
    /**
     * Determine whether the user can view any models.
     */
    public function viewAny(User $user): bool
    {
        return false;
    }

    /**
     * Determine whether the user can view the model.
     */
    public function view(User $user, SchoolOperatingRule $schoolOperatingRule): bool
    {
        return false;
    }

    /**
     * Determine whether the user can create models.
     */
    public function create(User $user): bool
    {
        return false;
    }

    /**
     * Determine whether the user can update the model.
     */
    public function update(User $user, SchoolOperatingRule $schoolOperatingRule): bool
    {
        return false;
    }

    /**
     * Determine whether the user can delete the model.
     */
    public function delete(User $user, SchoolOperatingRule $schoolOperatingRule): bool
    {
        return false;
    }

    /**
     * Determine whether the user can restore the model.
     */
    public function restore(User $user, SchoolOperatingRule $schoolOperatingRule): bool
    {
        return false;
    }

    /**
     * Determine whether the user can permanently delete the model.
     */
    public function forceDelete(User $user, SchoolOperatingRule $schoolOperatingRule): bool
    {
        return false;
    }
}
