<?php

namespace App\Policies\Schedules;

use App\Models\Auth\User;
use App\Models\Schedules\ScheduleRule;

class ScheduleRulePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->isAdmin();
    }

    public function create(User $user): bool
    {
        return $user->isAdmin();
    }

    public function view(User $user, ScheduleRule $rule): bool
    {
        return $user->isAdmin();
    }

    public function update(User $user, ScheduleRule $rule): bool
    {
        return $user->isAdmin();
    }

    public function delete(User $user, ScheduleRule $rule): bool
    {
        return $user->isAdmin();
    }
}
