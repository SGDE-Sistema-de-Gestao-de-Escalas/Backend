<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ScheduleEntry extends Model
{
    /** @use HasFactory<\Database\Factories\ScheduleEntryFactory> */
    use HasFactory;

    public function schedule()
    {
        return $this->belongsTo(Schedule::class);
    }

    public function activityType()
    {
        return $this->belongsTo(ActivityType::class);
    }
}
