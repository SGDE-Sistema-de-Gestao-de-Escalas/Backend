<?php

namespace App\Policies\Absences;

use App\Models\Absences\Absence;
use App\Models\Auth\User;

class AbsencePolicy
{
    /**
     * Determine whether the user can view any models.
     */
    public function viewAny(User $user): bool
    {
        return $user->isAdmin() || $user->isStaff();
    }

    /**
     * Determine whether the user can view the model.
     */
    public function view(User $user, Absence $absence): bool
    {
        if ($user->isAdmin()) {
            return true;
        }
        $userAstId = $user->assistant?->id;
        return ($absence->assistant?->user_id === $user->id) ||
               ($userAstId && (string) $absence->assistant_id === (string) $userAstId);
    }

    /**
     * Determine whether the user can create models.
     * Apenas os administradores podem criar ausências.
     */
    public function create(User $user): bool
    {
        return $user->isAdmin() || $user->isStaff();
    }

    /**
     * Determine whether the user can update the model.
     * O administrador pode atualizar todas; o assistente apenas a sua própria (para anexar documento ou notas).
     */
    public function update(User $user, Absence $absence): bool
    {
        if ($user->isAdmin()) {
            return true;
        }
        $userAstId = $user->assistant?->id;
        return ($absence->assistant?->user_id === $user->id) ||
               ($userAstId && (string) $absence->assistant_id === (string) $userAstId);
    }

    /**
     * Determine whether the user can delete the model.
     * Apenas os administradores podem eliminar ausências.
     */
    public function delete(User $user, Absence $absence): bool
    {
        return $user->isAdmin();
    }

    /**
     * Determine whether the user can restore the model.
     */
    public function restore(User $user, Absence $absence): bool
    {
        return $user->isAdmin();
    }

    /**
     * Determine whether the user can permanently delete the model.
     */
    public function forceDelete(User $user, Absence $absence): bool
    {
        return $user->isAdmin();
    }
}
