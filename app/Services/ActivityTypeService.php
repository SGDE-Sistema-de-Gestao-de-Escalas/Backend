<?php

namespace App\Services;

use App\Exceptions\ActivityTypeDeletionConflictException;
use App\Models\Schools\School;
use App\Models\System\ActivityType;
use Illuminate\Support\Facades\DB;

class ActivityTypeService
{
    /**
     * Atividades predefinidas, pedidas pelo cliente. Cada escola recebe a sua
     * cópia. Atividades que no horário do cliente surgem juntas (separadas por
     * "/") são atividades independentes que ocorrem em simultâneo.
     * Nome => cor.
     *
     * @var array<string, string>
     */
    public const DEFAULTS = [
        'Acolhimento' => '#F59E0B',
        'Apoio Turmas' => '#3B82F6',
        'Apoio Escola' => '#6366F1',
        'Intervalo' => '#10B981',
        'Vigilância almoço' => '#EF4444',
        'Limpeza' => '#8B5CF6',
        'Entrega de alunos' => '#14B8A6',
    ];

    public const DEFAULT_COLOR = '#6B7280';

    private const SYSTEM_BLOCK_MESSAGE = 'As atividades predefinidas não podem ser eliminadas. Pode desativá-la.';

    private const IN_USE_BLOCK_MESSAGE = 'Esta atividade está a ser usada em horários e não pode ser eliminada. Pode desativá-la.';

    public function create(array $data): ActivityType
    {
        // Explícitos para que a resposta já traga os valores corretos (o
        // default da base de dados não é refletido no modelo acabado de criar).
        return ActivityType::create($data + [
            'color' => self::DEFAULT_COLOR,
            'active' => true,
            'is_system' => false,
        ]);
    }

    public function update(ActivityType $activityType, array $data): ActivityType
    {
        $activityType->update($data);

        return $activityType;
    }

    /**
     * Cria as atividades predefinidas de uma escola. Só o faz se a escola
     * ainda não tiver nenhuma predefinida, por isso pode ser chamado várias
     * vezes (seeder, criação de escola) sem duplicar, mesmo que alguma
     * predefinida tenha sido renomeada entretanto.
     */
    public function seedDefaultsForSchool(School $school): void
    {
        DB::transaction(function () use ($school) {
            $alreadySeeded = ActivityType::where('school_id', $school->getKey())
                ->where('is_system', true)
                ->exists();

            if ($alreadySeeded) {
                return;
            }

            foreach (self::DEFAULTS as $name => $color) {
                ActivityType::create([
                    'school_id' => $school->getKey(),
                    'name' => $name,
                    'color' => $color,
                    'is_system' => true,
                    'active' => true,
                ]);
            }
        });
    }

    /**
     * Elimina a atividade, mas só depois de voltar a verificar, dentro de uma
     * transação com lock, se ainda pode ser eliminada. É esta a verificação
     * que decide o DELETE, independentemente do `can_delete` que o cliente
     * tenha visto antes.
     */
    public function delete(ActivityType $activityType): void
    {
        DB::transaction(function () use ($activityType) {
            $locked = ActivityType::whereKey($activityType->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            $blockReason = $this->deletionBlockReason($locked);

            if ($blockReason !== null) {
                throw new ActivityTypeDeletionConflictException($blockReason);
            }

            $locked->delete();
        });
    }

    /**
     * Argumento para `withCount()` / `loadCount()` que pré-carrega, numa só
     * consulta, a contagem usada por deletionBlockReason() (evita N+1).
     *
     * @return array<string, \Closure>
     */
    public static function blockingCounts(): array
    {
        return [
            'scheduleEntries as blocking_schedule_entries_count' => fn ($query) => $query,
        ];
    }

    /**
     * Única regra sobre se uma atividade pode ser eliminada: devolve `null`
     * se pode, ou a mensagem do bloqueio. Usada pelo Resource
     * (can_delete/cannot_delete_reason) e pelo próprio delete().
     */
    public function deletionBlockReason(ActivityType $activityType): ?string
    {
        if ($activityType->is_system) {
            return self::SYSTEM_BLOCK_MESSAGE;
        }

        $count = $activityType->blocking_schedule_entries_count
            ?? $activityType->scheduleEntries()->count();

        return $count > 0 ? self::IN_USE_BLOCK_MESSAGE : null;
    }

    public function canDelete(ActivityType $activityType): bool
    {
        return $this->deletionBlockReason($activityType) === null;
    }
}
