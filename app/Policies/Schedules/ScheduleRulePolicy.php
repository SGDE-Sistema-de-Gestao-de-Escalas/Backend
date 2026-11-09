<?php

namespace App\Policies\Schedules;

use App\Models\Auth\User;
use App\Models\Schedules\ScheduleRule;
use App\Policies\Concerns\HandlesSchoolScopedPolicy;

class ScheduleRulePolicy
{
    use HandlesSchoolScopedPolicy;

    public function view(User $user, ScheduleRule $rule): bool
    {
        return $user->isAdmin() || $user->isStaff();
    }
}
