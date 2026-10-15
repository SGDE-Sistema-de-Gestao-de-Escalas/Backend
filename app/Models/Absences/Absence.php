<?php

namespace App\Models\Absences;

use App\Models\Assistants\Assistant;
use App\Models\Auth\User;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class Absence extends Model
{
    /** @use HasFactory<AbsenceFactory> */
    use HasFactory, HasUuids, SoftDeletes;

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
        'document_path',
        'status',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'valid_from' => 'date',
            'valid_until' => 'date',
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
}
