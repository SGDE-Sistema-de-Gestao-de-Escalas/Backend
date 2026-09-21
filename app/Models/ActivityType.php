<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ActivityType extends Model
{
    /** @use HasFactory<\Database\Factories\ActivityTypeFactory> */
    use HasFactory;

    public function schools()
    {
        return $this->belongsToMany(School::class);
    }

    public function scheduleEntries()
    {
        return $this->hasMany(ScheduleEntry::class);
    }
}
