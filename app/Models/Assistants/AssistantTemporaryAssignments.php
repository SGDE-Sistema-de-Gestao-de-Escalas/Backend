<?php

namespace App\Models\Assistants;

use App\Models\Schools\School;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class AssistantTemporaryAssignments extends Model
{
    /** @use HasFactory<\Database\Factories\AssistantTemporaryAssignmentsFactory> */
    use HasFactory, HasUuids;

    protected $fillable = [
        'assistant_id',
        'origin_school_id',
        'destination_school_id',
        'created_by_user_id',
        'validated_by_user_id',
        'valid_from',
        'valid_until',
        'notes',
        'start_date',
        'end_date',
        'start_time',
        'end_time',
    ];

    protected function casts(): array
    {
        return [
            'valid_from' => 'date:Y-m-d',
            'valid_until' => 'date:Y-m-d',
        ];
    }

    public function assistant()
    {
        return $this->belongsTo(Assistant::class);
    }

    public function originSchool()
    {
        return $this->belongsTo(School::class, 'origin_school_id');
    }

    public function destinationSchool()
    {
        return $this->belongsTo(School::class, 'destination_school_id');
    }
}
