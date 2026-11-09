<?php

namespace App\Policies\Absences;

use App\Models\Absences\AbsenceType;
use App\Models\Auth\User;
use App\Policies\Concerns\HandlesSchoolScopedPolicy;

class AbsenceTypePolicy
{
    use HandlesSchoolScopedPolicy;

    public function view(User $user, AbsenceType $absenceType): bool
    {
        return $user->isAdmin() || $user->isStaff();
    }
}
