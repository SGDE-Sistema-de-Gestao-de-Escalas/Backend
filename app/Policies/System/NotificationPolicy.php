<?php

namespace App\Policies\System;

use App\Models\Auth\User;
use App\Models\System\Notification;

/**
 * As notificações são pessoais: cada utilizador só vê/marca/elimina as suas.
 * Não há criação manual (são geradas pelo sistema, ver NotificationService).
 */
class NotificationPolicy
{
    public function viewAny(User $user): bool
    {
        return true; // a listagem é sempre filtrada pelo próprio utilizador
    }

    public function view(User $user, Notification $notification): bool
    {
        return $notification->user_id === $user->id;
    }

    public function create(User $user): bool
    {
        return false;
    }

    public function update(User $user, Notification $notification): bool
    {
        return $notification->user_id === $user->id;
    }

    public function delete(User $user, Notification $notification): bool
    {
        return $notification->user_id === $user->id;
    }

    public function restore(User $user, Notification $notification): bool
    {
        return false;
    }

    public function forceDelete(User $user, Notification $notification): bool
    {
        return false;
    }
}
