<?php

namespace App\Services;

use App\Exceptions\SchoolDeletionConflictException;
use App\Models\Schools\School;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class SchoolService
{
    /**
     * Relações que impedem a eliminação de uma escola.
     *
     * Todas têm uma chave estrangeira para `schools` sem ON DELETE, por isso
     * apagar uma escola que ainda tenha qualquer uma destas linhas falharia
     * na base de dados. Contam inclusivamente os registos já eliminados
     * (soft delete): continuam a existir na tabela e a referenciar a escola.
     *
     * Exceção: as atividades (`activityTypes`) só bloqueiam se já estiverem
     * a ser usadas em entradas de horário. As predefinidas existem em todas
     * as escolas desde a criação e são removidas com a escola.
     *
     * Os horários de funcionamento (`schoolOperatingRules`) não bloqueiam: são
     * configuração da própria escola e, se mais nada a bloqueia, são removidos
     * com ela (sem isto, uma regra já em vigor, que não se pode apagar, tornava
     * a escola impossível de eliminar).
     */
    private const BLOCKING_RELATIONS = [
        'assistants',
        'schedules',
        'activityTypes',
        'outgoingTemporaryAssignments',
        'incomingTemporaryAssignments',
    ];

    private const BLOCK_MESSAGE = 'Esta escola tem registos associados e não pode ser eliminada.';

    public function __construct(
        private readonly ActivityTypeService $activityTypeService,
        private readonly SchoolOperatingRuleService $operatingRuleService
    ) {}

    /**
     * Cria a escola já com as atividades predefinidas (pedidas pelo cliente).
     */
    public function create(array $data): School
    {
        return DB::transaction(function () use ($data) {
            $school = School::create($data);

            $this->activityTypeService->seedDefaultsForSchool($school);

            return $school;
        });
    }

    public function update(School $school, array $data): School
    {
        $school->update($data);

        return $school;
    }

    /**
     * Elimina a escola, mas só depois de voltar a verificar, dentro de uma
     * transação com lock, se ela ainda pode ser eliminada. É aqui que a
     * verificação é feita de facto: não importa há quanto tempo foi mostrado
     * `can_delete: true` ao utilizador (numa listagem vista há horas, por
     * exemplo), esta é sempre a verificação que decide se o DELETE é aceite,
     * feita no preciso momento em que é pedido.
     */
    public function delete(School $school): void
    {
        DB::transaction(function () use ($school) {
            $lockedSchool = School::whereKey($school->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            $blockReason = $this->deletionBlockReason($lockedSchool);

            if ($blockReason !== null) {
                throw new SchoolDeletionConflictException($blockReason);
            }

            // As atividades (sem uso em horários) e os horários de
            // funcionamento acompanham a escola.
            $lockedSchool->activityTypes()->delete();
            $this->operatingRuleService->deleteAllForSchool($lockedSchool);

            $lockedSchool->delete();
        });
    }

    /**
     * Argumento para `withCount()` / `loadCount()` que pré-carrega, numa só
     * consulta, as contagens usadas por deletionBlockReason(). Evita o
     * problema N+1 nas listagens. Os atributos resultantes chamam-se
     * `blocking_<relação em snake_case>_count`.
     *
     * @return array<string, \Closure>
     */
    public static function blockingCounts(): array
    {
        $counts = [];

        foreach (self::BLOCKING_RELATIONS as $relation) {
            $counts[$relation.' as '.self::countAttribute($relation)] = fn ($query) => self::blockingQuery($relation, $query);
        }

        return $counts;
    }

    /**
     * Verificação única e reutilizável sobre se uma escola pode ser eliminada.
     *
     * Devolve `null` quando a escola não tem dependências (pode ser
     * eliminada), ou uma mensagem amigável a explicar o motivo do bloqueio
     * caso contrário. É o único método que decide a regra de negócio: usado
     * pelo SchoolResource (para expor `can_delete`/`cannot_delete_reason`
     * nas listagens e detalhes) e, de novo, pelo próprio delete() acima (a
     * verificação que efetivamente bloqueia o DELETE).
     */
    public function deletionBlockReason(School $school): ?string
    {
        foreach (self::BLOCKING_RELATIONS as $relation) {
            $count = $school->{self::countAttribute($relation)}
                ?? self::blockingQuery($relation, $school->{$relation}())->count();

            if ($count > 0) {
                return self::BLOCK_MESSAGE;
            }
        }

        return null;
    }

    public function canDelete(School $school): bool
    {
        return $this->deletionBlockReason($school) === null;
    }

    private static function countAttribute(string $relation): string
    {
        return 'blocking_'.Str::snake($relation).'_count';
    }

    /**
     * Restringe a contagem de uma relação bloqueante: as atividades só contam
     * se estiverem em uso em horários; as restantes contam todas as linhas,
     * incluindo as eliminadas.
     */
    private static function blockingQuery(string $relation, $query)
    {
        if ($relation === 'activityTypes') {
            return $query->whereHas('scheduleEntries');
        }

        return self::withDeleted($query);
    }

    /**
     * Inclui os registos eliminados (soft delete), se o model os suportar.
     */
    private static function withDeleted($query)
    {
        return in_array(SoftDeletes::class, class_uses_recursive($query->getModel()), true)
            ? $query->withTrashed()
            : $query;
    }
}
