<?php

namespace App\Models\Absences;

use App\Models\Assistants\Assistant;
use App\Models\Auth\User;
use Database\Factories\AbsenceFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Absence extends Model
{
    /** @use HasFactory<AbsenceFactory> */
    use HasFactory, HasUuids, SoftDeletes;

    protected $fillable = [
        'assistant_id',
        'absence_type_id',
        'valid_from',
        'valid_until',
        'total_days',
        'justification',
        'document_path',
        'notes',
    ];

    // Status and audit user IDs must be set explicitly by backend services.
    protected function casts(): array
    {
        return [
            'valid_from' => 'date:Y-m-d',
            'valid_until' => 'date:Y-m-d',
            'total_days' => 'integer',
        ];
    }

    public function createdBy()
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    public function justifiedBy()
    {
        return $this->belongsTo(User::class, 'justified_by_user_id');
    }

    public function assistant()
    {
        return $this->belongsTo(Assistant::class);
    }

    public function absenceType()
    {
        return $this->belongsTo(AbsenceType::class);
    }
}
