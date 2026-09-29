<?php

namespace App\Models\Schools;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUuids;

class School extends Model
{
    /** @use HasFactory<\Database\Factories\SchoolFactory> */
    use HasFactory, HasUuids;

    protected $fillable = [
        'name',
        'address',
        'phone',
        'email',
    ];

    public function assistants()
    {
        return $this->hasMany(Assistant::class);
    }

    public function activityTypes()
    {
        return $this->hasMany(ActivityType::class);
    }

    public function schoolOperatingRules()
    {
        return $this->hasMany(SchoolOperatingRule::class);
    }

    public function schedules()
    {
        return $this->hasMany(Schedule::class);
    }

    public function outgoingTemporaryAssignments()
    {
        return $this->hasMany(AssistantTemporaryAssignment::class, 'origin_school_id');
    }

    public function incomingTemporaryAssignments()
    {
        return $this->hasMany(AssistantTemporaryAssignment::class, 'destination_school_id');
    }
}

