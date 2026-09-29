<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUuids;

class AssistantScheduleShift extends Model
{
    /** @use HasFactory<\Database\Factories\AssistantScheduleShiftFactory> */
    use HasFactory, HasUuids;

    protected $fillable = [
        'assistant_schedule_profile_id',
        'day_of_week',
        'start_time',
        'end_time',
    ];

    public function assistantScheduleProfile()
    {
        return $this->belongsTo(AssistantScheduleProfile::class);
    }

    public function assistantScheduleShiftDays()
    {
        return $this->hasMany(AssistantScheduleShiftDay::class);
    }
}

