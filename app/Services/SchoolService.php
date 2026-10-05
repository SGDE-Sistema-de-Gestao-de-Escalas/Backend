<?php

namespace App\Services;

use App\Models\Schools\School;

class SchoolService
{
    public function create(array $data): School
    {
        return School::create($data);
    }

    public function update(School $school, array $data): School
    {
        $school->update($data);

        return $school;
    }

    public function delete(School $school): void
    {
        $school->delete();
    }

    /**
     * Verificação única e reutilizável sobre se uma escola pode ser eliminada.
     *
     * Devolve `null` quando a escola não tem dependências ativas (pode ser
     * eliminada), ou uma mensagem amigável a explicar o motivo do bloqueio
     * caso contrário. Este é o único método que decide a regra de negócio:
     * tanto o SchoolResource (para expor `can_delete`/`cannot_delete_reason`
     * nas listagens e detalhes) como o SchoolController::destroy (para
     * bloquear o DELETE com 409) chamam este método, para que a regra
     * nunca fique duplicada ou desalinhada entre os dois sítios.
     */
    public function deletionBlockReason(School $school): ?string
    {
        $assistantsCount = $school->assistants_count ?? $school->assistants()->count();

        $schedulesCount = $school->schedules_count
            ?? $school->schedules()->where('status', '!=', 'archived')->count();

        if ($assistantsCount === 0 && $schedulesCount === 0) {
            return null;
        }

        $reasons = [];

        if ($assistantsCount > 0) {
            $reasons[] = $assistantsCount === 1
                ? '1 assistente alocado'
                : "{$assistantsCount} assistentes alocados";
        }

        if ($schedulesCount > 0) {
            $reasons[] = $schedulesCount === 1
                ? '1 horário ativo'
                : "{$schedulesCount} horários ativos";
        }

        return 'Esta escola possui '.implode(' e ', $reasons)
            .'. Realoque os assistentes antes de eliminar.';
    }

    public function canDelete(School $school): bool
    {
        return $this->deletionBlockReason($school) === null;
    }
}
