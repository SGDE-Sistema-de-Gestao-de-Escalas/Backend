<?php

namespace App\Services;

use App\Exceptions\SchoolOperatingRuleConflictException;
use App\Models\Schools\School;
use App\Models\Schools\SchoolOperatingRule;
use App\Models\Schools\SchoolOperatingRuleDay;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Horário fixo da escola, com versões.
 *
 * Cada versão tem uma data de início (`valid_from`) e vale até a versão
 * seguinte da mesma escola começar: não tem data de fim. Para mudar o horário
 * cria-se uma versão nova a partir de uma data futura; as anteriores ficam
 * como histórico. Só as versões que ainda não começaram se alteram ou eliminam.
 */
class SchoolOperatingRuleService
{
    public const STATUS_UPCOMING = 'upcoming';

    public const STATUS_CURRENT = 'current';

    public const STATUS_SUPERSEDED = 'superseded';

    private const LOCKED_MESSAGE = 'Esta versão já entrou em vigor e não pode ser alterada. Para mudar o horário, crie uma versão nova a partir de uma data futura.';

    private const DELETE_BLOCK_MESSAGE = 'Esta versão já entrou em vigor e não pode ser eliminada. Para mudar o horário, crie uma versão nova a partir de uma data futura.';

    /**
     * Cria a versão e os seus dias. A escola é bloqueada durante a operação
     * para que dois pedidos em simultâneo não criem versões incoerentes.
     */
    public function create(array $data): SchoolOperatingRule
    {
        return DB::transaction(function () use ($data) {
            School::whereKey($data['school_id'])->lockForUpdate()->firstOrFail();

            $days = $this->normalizeDays($data['days']);

            $this->assertDaysConsistent($days);
            $this->assertStartDate($data['school_id'], $data['valid_from']);

            $rule = SchoolOperatingRule::create([
                'school_id' => $data['school_id'],
                'valid_from' => $data['valid_from'],
            ]);

            $this->syncDays($rule, $days);

            return $this->find($rule->getKey());
        });
    }

    /**
     * Só uma versão que ainda não começou pode ser alterada (data de início
     * e/ou horas dos dias). Se `days` for enviado, substitui a lista inteira.
     * Reenviar os mesmos valores não conta como alteração.
     */
    public function update(SchoolOperatingRule $rule, array $data): SchoolOperatingRule
    {
        return DB::transaction(function () use ($rule, $data) {
            School::whereKey($rule->school_id)->lockForUpdate()->first();

            $locked = SchoolOperatingRule::whereKey($rule->getKey())
                ->lockForUpdate()
                ->with('schoolOperatingRuleDays')
                ->firstOrFail();

            $validFrom = $data['valid_from'] ?? $locked->valid_from->toDateString();
            $days = isset($data['days'])
                ? $this->normalizeDays($data['days'])
                : $this->currentDays($locked);

            $validFromChanged = $validFrom !== $locked->valid_from->toDateString();
            $daysChanged = $this->sortDays($days) !== $this->currentDays($locked);

            if (! $validFromChanged && ! $daysChanged) {
                return $this->find($locked->getKey());
            }

            if ($this->status($locked) !== self::STATUS_UPCOMING) {
                throw new SchoolOperatingRuleConflictException(self::LOCKED_MESSAGE);
            }

            if ($daysChanged) {
                $this->assertDaysConsistent($days);
            }

            if ($validFromChanged) {
                $this->assertStartDate($locked->school_id, $validFrom, $locked->getKey());
                $locked->update(['valid_from' => $validFrom]);
            }

            if ($daysChanged) {
                $this->syncDays($locked, $days);
            }

            return $this->find($locked->getKey());
        });
    }

    /**
     * Elimina a versão, mas só depois de voltar a verificar, dentro de uma
     * transação com lock, se ainda pode ser eliminada (mesma regra do
     * `can_delete` que o cliente viu).
     */
    public function delete(SchoolOperatingRule $rule): void
    {
        DB::transaction(function () use ($rule) {
            $locked = SchoolOperatingRule::whereKey($rule->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            $blockReason = $this->deletionBlockReason($locked);

            if ($blockReason !== null) {
                throw new SchoolOperatingRuleConflictException($blockReason);
            }

            $locked->schoolOperatingRuleDays()->delete();
            $locked->delete();
        });
    }

    /**
     * Remove todas as versões (e os seus dias) de uma escola. Usado na
     * eliminação da escola, que já verificou que não tem dependências.
     */
    public function deleteAllForSchool(School $school): void
    {
        $ruleIds = $school->schoolOperatingRules()->pluck('id');

        SchoolOperatingRuleDay::whereIn('school_operating_rule_id', $ruleIds)->delete();
        $school->schoolOperatingRules()->delete();
    }

    /**
     * A versão com os dias e com o início da versão seguinte carregados.
     */
    public function find(string $id): SchoolOperatingRule
    {
        return SchoolOperatingRule::withNextVersion()
            ->with('schoolOperatingRuleDays')
            ->findOrFail($id);
    }

    /**
     * Fase da versão em relação a hoje: futura, em vigor ou substituída.
     */
    public function status(SchoolOperatingRule $rule): string
    {
        $today = today();

        if ($rule->valid_from->gt($today)) {
            return self::STATUS_UPCOMING;
        }

        $next = $this->nextValidFrom($rule);

        return $next !== null && $next->lte($today)
            ? self::STATUS_SUPERSEDED
            : self::STATUS_CURRENT;
    }

    /**
     * Último dia em que a versão vale (o dia anterior ao início da seguinte),
     * ou null se for a versão mais recente.
     */
    public function validUntil(SchoolOperatingRule $rule): ?string
    {
        return $this->nextValidFrom($rule)?->copy()->subDay()->toDateString();
    }

    /**
     * Única regra sobre se uma versão pode ser eliminada: `null` se pode, ou
     * a mensagem do bloqueio. Usada pelo Resource e pelo próprio delete().
     */
    public function deletionBlockReason(SchoolOperatingRule $rule): ?string
    {
        return $this->status($rule) === self::STATUS_UPCOMING ? null : self::DELETE_BLOCK_MESSAGE;
    }

    public function canDelete(SchoolOperatingRule $rule): bool
    {
        return $this->deletionBlockReason($rule) === null;
    }

    public function canEdit(SchoolOperatingRule $rule): bool
    {
        return $this->status($rule) === self::STATUS_UPCOMING;
    }

    /**
     * Início da versão seguinte da escola. Usa o valor já carregado por
     * withNextVersion() (listagens, sem N+1) e, se não existir, consulta-o.
     */
    private function nextValidFrom(SchoolOperatingRule $rule): ?Carbon
    {
        if (array_key_exists('next_valid_from', $rule->getAttributes())) {
            return $rule->next_valid_from;
        }

        $next = SchoolOperatingRule::where('school_id', $rule->school_id)
            ->whereDate('valid_from', '>', $rule->valid_from->toDateString())
            ->min('valid_from');

        return $next === null ? null : Carbon::parse($next)->startOfDay();
    }

    /**
     * A data de início tem de ser única na escola. Se a escola já tem outra
     * versão, a nova só pode começar numa data futura (só a primeira versão,
     * a configuração inicial, pode ter uma data passada).
     */
    private function assertStartDate(string $schoolId, string $validFrom, ?string $ignoreRuleId = null): void
    {
        $others = SchoolOperatingRule::where('school_id', $schoolId)
            ->when($ignoreRuleId, fn ($query) => $query->whereKeyNot($ignoreRuleId));

        if ((clone $others)->whereDate('valid_from', $validFrom)->exists()) {
            throw ValidationException::withMessages([
                'valid_from' => 'Já existe uma versão do horário fixo a começar nesta data.',
            ]);
        }

        if ($validFrom <= today()->toDateString() && $others->exists()) {
            throw ValidationException::withMessages([
                'valid_from' => 'A data de início tem de ser futura, porque a escola já tem outra versão do horário fixo.',
            ]);
        }
    }

    /**
     * Valida as horas de cada dia (na ordem em que foram enviados, para que os
     * erros apontem para a linha certa: `days.N.campo`).
     *
     * @param  list<array<string, mixed>>  $days
     */
    private function assertDaysConsistent(array $days): void
    {
        $errors = [];

        foreach ($days as $index => $day) {
            $key = "days.{$index}";

            if ($day['closing_time'] <= $day['opening_time']) {
                $errors["{$key}.closing_time"] = 'A hora de fecho tem de ser posterior à hora de abertura.';
            }

            if ($day['lunch_start'] < $day['opening_time']) {
                $errors["{$key}.lunch_start"] = 'O almoço não pode começar antes da abertura da escola.';
            }

            if ($day['lunch_end'] > $day['closing_time']) {
                $errors["{$key}.lunch_end"] = 'O almoço não pode terminar depois do fecho da escola.';
            }

            if ($day['lunch_end'] <= $day['lunch_start']) {
                $errors["{$key}.lunch_end"] = 'O fim do almoço tem de ser posterior ao início do almoço.';

                continue;
            }

            $window = $this->minutes($day['lunch_end']) - $this->minutes($day['lunch_start']);

            if ($day['lunch_duration_minutes'] > $window) {
                $errors["{$key}.lunch_duration_minutes"] = "A duração do almoço não pode ser superior à janela de almoço ({$window} minutos).";
            }
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
    }

    /**
     * Põe os dias recebidos num formato único (horas HH:MM, números inteiros),
     * mantendo a ordem de envio.
     *
     * @param  list<array<string, mixed>>  $days
     * @return list<array<string, mixed>>
     */
    private function normalizeDays(array $days): array
    {
        return array_map(fn (array $day) => [
            'day_of_week' => (int) $day['day_of_week'],
            'opening_time' => substr((string) $day['opening_time'], 0, 5),
            'closing_time' => substr((string) $day['closing_time'], 0, 5),
            'lunch_start' => substr((string) $day['lunch_start'], 0, 5),
            'lunch_end' => substr((string) $day['lunch_end'], 0, 5),
            'lunch_duration_minutes' => (int) $day['lunch_duration_minutes'],
        ], array_values($days));
    }

    /**
     * @param  list<array<string, mixed>>  $days
     * @return list<array<string, mixed>>
     */
    private function sortDays(array $days): array
    {
        usort($days, fn (array $a, array $b) => $a['day_of_week'] <=> $b['day_of_week']);

        return $days;
    }

    /**
     * Dias guardados da versão, no mesmo formato e ordem dos normalizados.
     *
     * @return list<array<string, mixed>>
     */
    private function currentDays(SchoolOperatingRule $rule): array
    {
        return $this->sortDays($this->normalizeDays(
            $rule->schoolOperatingRuleDays->map->only([
                'day_of_week', 'opening_time', 'closing_time', 'lunch_start', 'lunch_end', 'lunch_duration_minutes',
            ])->all()
        ));
    }

    /**
     * @param  list<array<string, mixed>>  $days
     */
    private function syncDays(SchoolOperatingRule $rule, array $days): void
    {
        $rule->schoolOperatingRuleDays()->delete();

        foreach ($this->sortDays($days) as $day) {
            $rule->schoolOperatingRuleDays()->create($day);
        }
    }

    private function minutes(string $time): int
    {
        [$hours, $minutes] = explode(':', $time);

        return ((int) $hours * 60) + (int) $minutes;
    }
}
