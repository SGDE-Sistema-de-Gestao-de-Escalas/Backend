<?php

namespace App\Policies\Schedules;

use App\Models\Schedules\AssistantScheduleShiftDay;
use App\Models\Auth\User;
use Illuminate\Auth\Access\Response;

class AssistantScheduleShiftDayPolicy
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
    public function view(User $user, AssistantScheduleShiftDay $assistantScheduleShiftDay): bool
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
    public function update(User $user, AssistantScheduleShiftDay $assistantScheduleShiftDay): bool
    {
        return false;
    }

    /**
     * Determine whether the user can delete the model.
     */
    public function delete(User $user, AssistantScheduleShiftDay $assistantScheduleShiftDay): bool
    {
        return false;
    }

    /**
     * Determine whether the user can restore the model.
     */
    public function restore(User $user, AssistantScheduleShiftDay $assistantScheduleShiftDay): bool
    {
        return false;
    }

    /**
     * Determine whether the user can permanently delete the model.
     */
    public function forceDelete(User $user, AssistantScheduleShiftDay $assistantScheduleShiftDay): bool
    {
        return false;
    }
}
