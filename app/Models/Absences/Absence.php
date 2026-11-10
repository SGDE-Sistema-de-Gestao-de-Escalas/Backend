<?php

namespace App\Models\Absences;

use App\Models\Assistants\Assistant;
use App\Models\Auth\User;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class Absence extends Model
{
    use HasUuids, SoftDeletes;

    protected $fillable = [
        'assistant_id',
        'absence_type_id',
        'created_by_user_id',
        'justified_by_user_id',
        'valid_from',
        'valid_until',
        'start_time',
        'end_time',
        'total_days',
        'justification',
        'document_name',
        'document_path',
        'status',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'valid_from' => 'date:Y-m-d',
            'valid_until' => 'date:Y-m-d',
            'total_days' => 'integer',
        ];
    }

    public function assistant(): BelongsTo
    {
        return $this->belongsTo(Assistant::class);
    }

    public function absenceType(): BelongsTo
    {
        return $this->belongsTo(AbsenceType::class);
    }

    public function createdByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    public function justifiedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'justified_by_user_id');
    }

    /**
     * Formata o período da falta para exibição nos emails e notificações (ex: "10/01/2026 a 12/01/2026 (09:00 às 12:00)").
     */
    public function formatPeriod(): string
    {
        $validFrom = $this->valid_from
            ? Carbon::parse($this->valid_from)->format('d/m/Y')
            : '';
        $validUntil = $this->valid_until
            ? Carbon::parse($this->valid_until)->format('d/m/Y')
            : $validFrom;

        $periodLabel = ($validFrom === $validUntil || $validUntil === '')
            ? $validFrom
            : "{$validFrom} a {$validUntil}";

        if ($this->start_time && $this->end_time) {
            $start = substr((string) $this->start_time, 0, 5);
            $end = substr((string) $this->end_time, 0, 5);
            $periodLabel .= " ({$start} às {$end})";
        }

        return $periodLabel;
    }
}
