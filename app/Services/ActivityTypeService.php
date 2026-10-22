<?php

namespace App\Services;

use App\Exceptions\ActivityTypeDeletionConflictException;
use App\Models\Schools\School;
use App\Models\System\ActivityType;
use Database\Seeders\ActivityTypeSeeder;
use Illuminate\Support\Facades\DB;

class ActivityTypeService
{
    /**
     * Atividades predefinidas (mantido por compatibilidade com testes/referências).
     * A lógica de seeding reside em Database\Seeders\ActivityTypeSeeder.
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

    public const FALLBACK_NAME = 'Apoio Escola';

    private const FALLBACK_BLOCK_MESSAGE = 'A atividade base de preenchimento automático não pode ser eliminada nem desativada.';

    private const SYSTEM_BLOCK_MESSAGE = 'As atividades predefinidas não podem ser eliminadas. Pode desativá-la.';

    private const IN_USE_BLOCK_MESSAGE = 'Esta atividade está a ser usada em horários e não pode ser eliminada. Pode desativá-la.';

    public function create(array $data): ActivityType
    {
        return ActivityType::create($data + [
            'color' => self::DEFAULT_COLOR,
            'active' => true,
            'is_system' => false,
            'is_fallback' => false,
        ]);
    }

    public function update(ActivityType $activityType, array $data): ActivityType
    {
        if ($activityType->is_fallback) {
            $data['active'] = true;
        }

        $activityType->update($data);

        return $activityType;
    }

    /**
     * Delega no ActivityTypeSeeder a criação das atividades predefinidas
     * quando uma nova escola é criada.
     */
    public function seedDefaultsForSchool(School $school): void
    {
        app(ActivityTypeSeeder::class)->seedForSchool($school);
    }

    /**
     * Elimina a atividade, mas só depois de voltar a verificar, dentro de uma
     * transação com lock, se ainda pode ser eliminada.
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
            'scheduleRules as blocking_schedule_rules_count' => fn ($query) => $query,
        ];
    }

    /**
     * Única regra sobre se uma atividade pode ser eliminada: devolve `null`
     * se pode, ou a mensagem do bloqueio.
     */
    public function deletionBlockReason(ActivityType $activityType): ?string
    {
        if ($activityType->is_fallback) {
            return self::FALLBACK_BLOCK_MESSAGE;
        }

        if ($activityType->is_system) {
            return self::SYSTEM_BLOCK_MESSAGE;
        }

        $count = $activityType->blocking_schedule_entries_count
            ?? $activityType->scheduleEntries()->count();

        $ruleCount = $activityType->blocking_schedule_rules_count
            ?? $activityType->scheduleRules()->count();

        if ($ruleCount > 0) {
            return 'Esta atividade está a ser usada em regras de escala e não pode ser eliminada. Pode desativá-la.';
        }

        return $count > 0 ? self::IN_USE_BLOCK_MESSAGE : null;
    }

    public function canDelete(ActivityType $activityType): bool
    {
        return $this->deletionBlockReason($activityType) === null;
    }
}
