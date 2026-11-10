<?php

namespace App\Policies\Assistants;

use App\Models\Assistants\AssistantException;
use App\Models\Auth\User;
use App\Policies\Concerns\HandlesSchoolScopedPolicy;

class AssistantExceptionPolicy
{
    use HandlesSchoolScopedPolicy;

    public function view(User $user, AssistantException $assistantException): bool
    {
        return $user->isAdmin() || $assistantException->assistant?->user_id === $user->id;
    }
}
