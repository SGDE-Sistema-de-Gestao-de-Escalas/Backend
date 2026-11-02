<?php

namespace App\Models\Schools;

use Database\Factories\SchoolOperatingExceptionFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Exceção ao horário fixo de uma escola: durante o período indicado (datas
 * inclusivas) a escola fecha ou funciona com as horas desta exceção, as mesmas
 * em todos os dias do período. Só altera os dias em que o horário fixo
 * funciona.
 */
class SchoolOperatingException extends Model
{
    /** @use HasFactory<\Database\Factories\SchoolOperatingExceptionFactory> */
    use HasFactory, HasUuids;

    protected $fillable = [
        'school_id',
        'name',
        'is_closed',
        'opening_time',
        'closing_time',
        'lunch_start',
        'lunch_end',
        'lunch_duration_minutes',
        'valid_from',
        'valid_until',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_closed' => 'boolean',
            'lunch_duration_minutes' => 'integer',
            'valid_from' => 'date:Y-m-d',
            'valid_until' => 'date:Y-m-d',
        ];
    }

    /**
     * As factories do projeto estão todas em Database\Factories (sem a pasta
     * do domínio), por isso a convenção do Laravel não as encontra.
     */
    protected static function newFactory(): SchoolOperatingExceptionFactory
    {
        return SchoolOperatingExceptionFactory::new();
    }

    public function school()
    {
        return $this->belongsTo(School::class);
    }
}
