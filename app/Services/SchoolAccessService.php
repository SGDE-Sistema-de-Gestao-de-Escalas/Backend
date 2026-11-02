<?php

namespace App\Services;

use App\Models\Assistants\Assistant;
use App\Models\Assistants\AssistantTemporaryAssignments;
use App\Models\Auth\User;
use App\Models\Schools\School;

class SchoolAccessService
{
    /**
     * Indica se o utilizador pode aceder (trabalhar no contexto de) à escola.
     *
     * - Administrador: qualquer escola que exista.
     * - Restantes utilizadores: a escola do seu assistente ou a escola de
     *   destino de uma afetação temporária em vigor.
     *
     * É o único sítio com esta regra, para ser reutilizada (middleware,
     * policies, controllers).
     */
    public function canAccess(User $user, string $schoolId): bool
    {
        if ($user->isAdmin()) {
            return School::whereKey($schoolId)->exists();
        }

        $today = today();

        return Assistant::where('user_id', $user->getKey())
            ->where(function ($query) use ($schoolId, $today) {
                $query->where('school_id', $schoolId)
                    ->orWhereHas('assistantTemporaryAssignments', function ($assignment) use ($schoolId, $today) {
                        $assignment->where('destination_school_id', $schoolId)
                            ->whereDate('valid_from', '<=', $today)
                            ->where(function ($until) use ($today) {
                                $until->whereNull('valid_until')
                                    ->orWhereDate('valid_until', '>=', $today);
                            });
                    });
            })
            ->exists();
    }

    /**
     * IDs das escolas a que um utilizador NÃO administrador tem acesso: a escola
     * do seu assistente e as escolas de destino de cedências temporárias em
     * vigor. Sem assistente associado, a lista é vazia. (Para o administrador
     * não se aplica: vê todas.)
     *
     * @return array<int, string>
     */
    public function accessibleSchoolIds(User $user): array
    {
        $today = today();

        $own = Assistant::where('user_id', $user->getKey())->pluck('school_id');

        $temporary = AssistantTemporaryAssignments::query()
            ->whereHas('assistant', fn ($assistant) => $assistant->where('user_id', $user->getKey()))
            ->whereDate('valid_from', '<=', $today)
            ->where(function ($until) use ($today) {
                $until->whereNull('valid_until')
                    ->orWhereDate('valid_until', '>=', $today);
            })
            ->pluck('destination_school_id');

        return $own->merge($temporary)->filter()->unique()->values()->all();
    }
}
