<?php

namespace App\Models\Schools;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Horas de um dia da semana (1 = segunda ... 7 = domingo) numa versão do
 * horário fixo.
 */
class SchoolOperatingRuleDay extends Model
{
    /** @use HasFactory<\Database\Factories\SchoolOperatingRuleDayFactory> */
    use HasFactory, HasUuids;

    protected $fillable = [
        'school_operating_rule_id',
        'day_of_week',
        'opening_time',
        'closing_time',
        'lunch_start',
        'lunch_end',
        'lunch_duration_minutes',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'day_of_week' => 'integer',
            'lunch_duration_minutes' => 'integer',
        ];
    }

    public function schoolOperatingRule()
    {
        return $this->belongsTo(SchoolOperatingRule::class);
    }
}
