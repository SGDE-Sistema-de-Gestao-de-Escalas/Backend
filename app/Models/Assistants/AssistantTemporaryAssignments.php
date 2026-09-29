<?php

namespace App\Models\Assistants;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUuids;

class AssistantTemporaryAssignments extends Model
{
    /** @use HasFactory<\Database\Factories\AssistantTemporaryAssignmentsFactory> */
    use HasFactory, HasUuids;

    protected $fillable = [
        'assistant_id',
        'origin_school_id',
        'destination_school_id',
        'start_date',
        'end_date',
    ];

    public function assistant()
    {
        return $this->belongsTo(Assistant::class);
    }

    public function originSchool()
    {
        return $this->belongsTo(School::class);
    }

    public function destinationSchool()
    {
        return $this->belongsTo(School::class);
    }
}

