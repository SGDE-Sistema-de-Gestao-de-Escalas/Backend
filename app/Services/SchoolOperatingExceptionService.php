<?php

namespace App\Services;

use App\Exceptions\SchoolOperatingExceptionConflictException;
use App\Models\Schools\School;
use App\Models\Schools\SchoolOperatingException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Exceções ao horário fixo da escola.
 *
 * Cada exceção tem um período obrigatório (início e fim inclusivos) e fecha a
 * escola ou define umas horas únicas para todos os dias do período. Numa
 * escola as exceções não se sobrepõem. Uma exceção futura altera-se e elimina-se
 * por inteiro; uma em curso só permite mudar a data de fim; uma terminada fica
 * bloqueada.
 */
class SchoolOperatingExceptionService
{
    public const STATUS_UPCOMING = 'upcoming';

    public const STATUS_ACTIVE = 'active';

    public const STATUS_ENDED = 'ended';

    private const HOUR_FIELDS = ['opening_time', 'closing_time', 'lunch_start', 'lunch_end'];

    private const ENDED_MESSAGE = 'Esta exceção já terminou e não pode ser alterada.';

    private const ACTIVE_MESSAGE = 'Esta exceção já está em curso: só é possível alterar a data de fim.';

    private const DELETE_ACTIVE_MESSAGE = 'Esta exceção já está em curso e não pode ser eliminada. Pode encurtá-la alterando a data de fim.';

    private const DELETE_ENDED_MESSAGE = 'Esta exceção já terminou e não pode ser eliminada.';

    /**
     * Cria a exceção. A escola é bloqueada durante a operação para que dois
     * pedidos em simultâneo não criem exceções sobrepostas.
     */
    public function create(array $data): SchoolOperatingException
    {
        return DB::transaction(function () use ($data) {
            School::whereKey($data['school_id'])->lockForUpdate()->firstOrFail();

            $values = $this->normalize($data);

            $this->assertHours($values);
            $this->assertPeriod($values['valid_from'], $values['valid_until'], checkStartInPast: true);
            $this->assertNoOverlap($data['school_id'], $values['valid_from'], $values['valid_until']);

            return SchoolOperatingException::create(['school_id' => $data['school_id']] + $values)->fresh();
        });
    }

    /**
     * Futura: pode mudar tudo. Em curso: só `valid_until`. Terminada: nada.
     * Os campos não enviados mantêm-se; reenviar os mesmos valores não conta
     * como alteração (o cliente pode enviar o objeto completo).
     */
    public function update(SchoolOperatingException $exception, array $data): SchoolOperatingException
    {
        return DB::transaction(function () use ($exception, $data) {
            School::whereKey($exception->school_id)->lockForUpdate()->first();

            $locked = SchoolOperatingException::whereKey($exception->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            $current = $this->normalize($locked->only($this->fields()));
            $values = $this->normalize($data + $current);

            $changed = array_keys(array_filter(
                $values,
                fn ($value, $field) => $value !== $current[$field],
                ARRAY_FILTER_USE_BOTH
            ));

            if ($changed === []) {
                return $locked;
            }

            $status = $this->status($locked);

            if ($status === self::STATUS_ENDED) {
                throw new SchoolOperatingExceptionConflictException(self::ENDED_MESSAGE);
            }

            if ($status === self::STATUS_ACTIVE && array_diff($changed, ['valid_until']) !== []) {
                throw new SchoolOperatingExceptionConflictException(self::ACTIVE_MESSAGE);
            }

            $this->assertHours($values);
            $this->assertPeriod(
                $values['valid_from'],
                $values['valid_until'],
                checkStartInPast: in_array('valid_from', $changed, true)
            );
            $this->assertNoOverlap($locked->school_id, $values['valid_from'], $values['valid_until'], $locked->getKey());

            $locked->update($values);

            return $locked->fresh();
        });
    }

    /**
     * Elimina a exceção, mas só depois de voltar a verificar, dentro de uma
     * transação com lock, se ainda pode ser eliminada (só as futuras).
     */
    public function delete(SchoolOperatingException $exception): void
    {
        DB::transaction(function () use ($exception) {
            $locked = SchoolOperatingException::whereKey($exception->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            $blockReason = $this->deletionBlockReason($locked);

            if ($blockReason !== null) {
                throw new SchoolOperatingExceptionConflictException($blockReason);
            }

            $locked->delete();
        });
    }

    /**
     * Filtra pela fase: `upcoming`, `active` (em curso hoje) ou `ended`.
     */
    public function filterByStatus(Builder $query, string $status): Builder
    {
        $today = today()->toDateString();

        return match ($status) {
            self::STATUS_UPCOMING => $query->whereDate('valid_from', '>', $today),
            self::STATUS_ACTIVE => $query->whereDate('valid_from', '<=', $today)->whereDate('valid_until', '>=', $today),
            self::STATUS_ENDED => $query->whereDate('valid_until', '<', $today),
        };
    }

    public function status(SchoolOperatingException $exception): string
    {
        $today = today();

        if ($exception->valid_from->gt($today)) {
            return self::STATUS_UPCOMING;
        }

        return $exception->valid_until->lt($today) ? self::STATUS_ENDED : self::STATUS_ACTIVE;
    }

    /**
     * Única regra sobre se uma exceção pode ser eliminada: `null` se pode, ou a
     * mensagem do bloqueio. Usada pelo Resource e pelo próprio delete().
     */
    public function deletionBlockReason(SchoolOperatingException $exception): ?string
    {
        return match ($this->status($exception)) {
            self::STATUS_UPCOMING => null,
            self::STATUS_ACTIVE => self::DELETE_ACTIVE_MESSAGE,
            self::STATUS_ENDED => self::DELETE_ENDED_MESSAGE,
        };
    }

    public function canEditAll(SchoolOperatingException $exception): bool
    {
        return $this->status($exception) === self::STATUS_UPCOMING;
    }

    public function canChangeEndDate(SchoolOperatingException $exception): bool
    {
        return $this->status($exception) === self::STATUS_ACTIVE;
    }

    /**
     * @return list<string>
     */
    private function fields(): array
    {
        return [
            'name', 'is_closed', ...self::HOUR_FIELDS,
            'lunch_duration_minutes', 'valid_from', 'valid_until',
        ];
    }

    /**
     * Põe os valores num formato único e comparável: horas HH:MM, datas
     * AAAA-MM-DD, booleano, e as horas a null quando a escola fecha.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function normalize(array $data): array
    {
        $isClosed = (bool) ($data['is_closed'] ?? false);

        $values = [
            'name' => trim((string) ($data['name'] ?? '')),
            'is_closed' => $isClosed,
            'valid_from' => $this->date($data['valid_from'] ?? null),
            'valid_until' => $this->date($data['valid_until'] ?? null),
        ];

        foreach (self::HOUR_FIELDS as $field) {
            $value = $data[$field] ?? null;
            $values[$field] = $isClosed || $value === null ? null : substr((string) $value, 0, 5);
        }

        $duration = $data['lunch_duration_minutes'] ?? null;
        $values['lunch_duration_minutes'] = $isClosed || $duration === null ? null : (int) $duration;

        return $values;
    }

    private function date(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        return $value instanceof \DateTimeInterface ? $value->format('Y-m-d') : substr((string) $value, 0, 10);
    }

    /**
     * Se a escola não fecha, tem de ter todas as horas e estas têm de ser
     * coerentes (mesmas regras de cada dia do horário fixo).
     *
     * @param  array<string, mixed>  $values
     */
    private function assertHours(array $values): void
    {
        if ($values['is_closed']) {
            return;
        }

        $labels = [
            'opening_time' => 'A hora de abertura é obrigatória.',
            'closing_time' => 'A hora de fecho é obrigatória.',
            'lunch_start' => 'O início do almoço é obrigatório.',
            'lunch_end' => 'O fim do almoço é obrigatório.',
            'lunch_duration_minutes' => 'A duração do almoço é obrigatória.',
        ];

        $errors = [];

        foreach ($labels as $field => $message) {
            if ($values[$field] === null) {
                $errors[$field] = $message;
            }
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        if ($values['closing_time'] <= $values['opening_time']) {
            $errors['closing_time'] = 'A hora de fecho tem de ser posterior à hora de abertura.';
        }

        if ($values['lunch_start'] < $values['opening_time']) {
            $errors['lunch_start'] = 'O almoço não pode começar antes da abertura da escola.';
        }

        if ($values['lunch_end'] > $values['closing_time']) {
            $errors['lunch_end'] = 'O almoço não pode terminar depois do fecho da escola.';
        }

        if ($values['lunch_end'] <= $values['lunch_start']) {
            $errors['lunch_end'] = 'O fim do almoço tem de ser posterior ao início do almoço.';
        } else {
            $window = $this->minutes($values['lunch_end']) - $this->minutes($values['lunch_start']);

            if ($values['lunch_duration_minutes'] > $window) {
                $errors['lunch_duration_minutes'] = "A duração do almoço não pode ser superior à janela de almoço ({$window} minutos).";
            }
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
    }

    /**
     * O fim não pode ser anterior ao início, nem estar no passado. O início só
     * não pode estar no passado quando é novo (criação ou alteração do início).
     */
    private function assertPeriod(string $validFrom, string $validUntil, bool $checkStartInPast): void
    {
        $today = today()->toDateString();
        $errors = [];

        if ($checkStartInPast && $validFrom < $today) {
            $errors['valid_from'] = 'A data de início não pode estar no passado.';
        }

        if ($validUntil < $validFrom) {
            $errors['valid_until'] = 'A data de fim não pode ser anterior à data de início.';
        } elseif ($validUntil < $today) {
            $errors['valid_until'] = 'A data de fim não pode estar no passado.';
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
    }

    /**
     * As exceções da mesma escola não se podem sobrepor (períodos inclusivos).
     */
    private function assertNoOverlap(string $schoolId, string $validFrom, string $validUntil, ?string $ignoreId = null): void
    {
        $overlapping = SchoolOperatingException::where('school_id', $schoolId)
            ->when($ignoreId, fn ($query) => $query->whereKeyNot($ignoreId))
            ->whereDate('valid_from', '<=', $validUntil)
            ->whereDate('valid_until', '>=', $validFrom)
            ->orderBy('valid_from')
            ->first();

        if ($overlapping === null) {
            return;
        }

        $from = $overlapping->valid_from->format('d/m/Y');
        $until = $overlapping->valid_until->format('d/m/Y');

        throw ValidationException::withMessages([
            'valid_from' => "O período sobrepõe-se à exceção «{$overlapping->name}» ({$from} a {$until}).",
        ]);
    }

    private function minutes(string $time): int
    {
        [$hours, $minutes] = explode(':', $time);

        return ((int) $hours * 60) + (int) $minutes;
    }
}
