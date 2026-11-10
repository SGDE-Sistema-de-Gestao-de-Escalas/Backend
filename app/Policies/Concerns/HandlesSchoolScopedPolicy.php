<?php

namespace App\Policies\Concerns;

use App\Models\Auth\User;
use App\Services\Schools\SchoolAccessService;
use Illuminate\Database\Eloquent\Model;

trait HandlesSchoolScopedPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->isAdmin() || $user->isStaff();
    }

    public function create(User $user): bool
    {
        return $user->isAdmin();
    }

    public function update(User $user, Model $model): bool
    {
        return $user->isAdmin();
    }

    public function delete(User $user, Model $model): bool
    {
        return $user->isAdmin();
    }

    public function restore(User $user, Model $model): bool
    {
        return $user->isAdmin();
    }

    public function forceDelete(User $user, Model $model): bool
    {
        return $user->isAdmin();
    }

    protected function canViewSchoolResource(User $user, ?string $schoolId): bool
    {
        if ($user->isAdmin()) {
            return true;
        }

        return $user->isStaff()
            && $schoolId !== null
            && app(SchoolAccessService::class)->canAccess($user, $schoolId);
    }
}
