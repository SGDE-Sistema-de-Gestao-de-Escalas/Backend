<?php

namespace App\Models\Schools;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Uma versão do horário fixo de uma escola. Vale a partir de `valid_from` até
 * a versão seguinte da mesma escola começar (não tem data de fim). As horas de
 * cada dia da semana estão em SchoolOperatingRuleDay.
 */
class SchoolOperatingRule extends Model
{
    /** @use HasFactory<\Database\Factories\SchoolOperatingRuleFactory> */
    use HasFactory, HasUuids;

    protected $fillable = [
        'school_id',
        'valid_from',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'valid_from' => 'date:Y-m-d',
            // Só existe quando a consulta usa withNextVersion().
            'next_valid_from' => 'date:Y-m-d',
        ];
    }

    public function school()
    {
        return $this->belongsTo(School::class);
    }

    /**
     * Horas de cada dia da semana em que a escola funciona (1 = segunda ...
     * 7 = domingo).
     */
    public function schoolOperatingRuleDays()
    {
        return $this->hasMany(SchoolOperatingRuleDay::class);
    }

    /**
     * Acrescenta `next_valid_from`: o início da versão seguinte da mesma escola
     * (null na mais recente). Permite saber, numa só consulta, até quando
     * vale cada versão e se já foi substituída.
     */
    public function scopeWithNextVersion(Builder $query): void
    {
        $query->addSelect([
            'next_valid_from' => static::query()
                ->from('school_operating_rules as next_rules')
                ->selectRaw('min(next_rules.valid_from)')
                ->whereColumn('next_rules.school_id', 'school_operating_rules.school_id')
                ->whereColumn('next_rules.valid_from', '>', 'school_operating_rules.valid_from'),
        ]);
    }

    /**
     * Filtra pela fase: `upcoming` (ainda não começou), `current` (em vigor
     * hoje) ou `superseded` (já substituída por uma versão que começou).
     */
    public function scopeInStatus(Builder $query, string $status, string $today): void
    {
        $startedLater = fn ($sub) => $sub->from('school_operating_rules as later_rules')
            ->selectRaw('1')
            ->whereColumn('later_rules.school_id', 'school_operating_rules.school_id')
            ->whereColumn('later_rules.valid_from', '>', 'school_operating_rules.valid_from')
            ->whereDate('later_rules.valid_from', '<=', $today);

        match ($status) {
            'upcoming' => $query->whereDate('school_operating_rules.valid_from', '>', $today),
            'current' => $query->whereDate('school_operating_rules.valid_from', '<=', $today)
                ->whereNotExists($startedLater),
            'superseded' => $query->whereDate('school_operating_rules.valid_from', '<=', $today)
                ->whereExists($startedLater),
        };
    }
}
