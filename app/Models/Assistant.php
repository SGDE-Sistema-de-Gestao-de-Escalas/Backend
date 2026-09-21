<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Assistant extends Model
{
    /** @use HasFactory<\Database\Factories\AssistantFactory> */
    use HasFactory;

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function assistantExceptions()
    {
        return $this->hasMany(AssistantException::class);
    }

    public function schedules()
    {
        return $this->hasMany(Schedule::class);
    }

    public function absences()
    {
        return $this->hasMany(Absence::class);
    }

    public function assistantTemporaryAssignments()
    {
        return $this->hasMany(AssistantTemporaryAssignment::class);
    }

    public function assistantScheduleProfiles()
    {
        return $this->hasMany(AssistantScheduleProfile::class);
    }
}
