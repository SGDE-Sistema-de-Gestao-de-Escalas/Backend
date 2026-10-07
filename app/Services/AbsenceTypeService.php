<?php

namespace App\Services;

use App\Exceptions\AbsenceTypeDeletionConflictException;
use App\Models\Absences\AbsenceType;
use Illuminate\Support\Facades\DB;

class AbsenceTypeService
{
    private const BLOCK_MESSAGE = 'Este tipo de falta tem faltas associadas e não pode ser eliminado.';

    public function create(array $data): AbsenceType
    {
        // Por omissão não exige documento (igual ao default da base de dados,
        // mas explícito para que a resposta já traga o valor correto).
        return AbsenceType::create($data + ['requires_document' => false]);
    }

    public function update(AbsenceType $absenceType, array $data): AbsenceType
    {
        $absenceType->update($data);

        return $absenceType;
    }

    /**
     * Elimina o tipo de falta, mas só depois de voltar a verificar, dentro de
     * uma transação com lock, se ainda pode ser eliminado. Esta é a
     * verificação que decide o DELETE, feita no instante em que é pedido,
     * independentemente do `can_delete` que o cliente tenha visto antes.
     */
    public function delete(AbsenceType $absenceType): void
    {
        DB::transaction(function () use ($absenceType) {
            $locked = AbsenceType::whereKey($absenceType->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            $blockReason = $this->deletionBlockReason($locked);

            if ($blockReason !== null) {
                throw new AbsenceTypeDeletionConflictException($blockReason);
            }

            $locked->delete();
        });
    }

    /**
     * Argumento para `withCount()` / `loadCount()` que pré-carrega, numa só
     * consulta, a contagem usada por deletionBlockReason() (evita N+1).
     * Conta também as faltas eliminadas (soft delete): continuam na tabela e
     * a referenciar o tipo, por isso continuam a impedir a eliminação.
     *
     * @return array<string, \Closure>
     */
    public static function blockingCounts(): array
    {
        return [
            'absences as blocking_absences_count' => fn ($query) => $query->withTrashed(),
        ];
    }

    /**
     * Única regra sobre se um tipo de falta pode ser eliminado: devolve `null`
     * se pode, ou a mensagem do bloqueio. Usada pelo Resource
     * (can_delete/cannot_delete_reason) e pelo próprio delete().
     */
    public function deletionBlockReason(AbsenceType $absenceType): ?string
    {
        $count = $absenceType->blocking_absences_count
            ?? $absenceType->absences()->withTrashed()->count();

        return $count > 0 ? self::BLOCK_MESSAGE : null;
    }

    public function canDelete(AbsenceType $absenceType): bool
    {
        return $this->deletionBlockReason($absenceType) === null;
    }
}
