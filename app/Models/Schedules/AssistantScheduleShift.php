<?php

namespace App\Models\Schedules;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUuids;

class AssistantScheduleShift extends Model
{
    /** @use HasFactory<\Database\Factories\AssistantScheduleShiftFactory> */
    use HasFactory, HasUuids;

    protected $fillable = [
        'assistant_schedule_profile_id',
        'shift_label',
        'entry_time',
        'exit_time',
        'lunch_enabled',
        'lunch_start',
        'lunch_end',
        'lunch_duration_minutes',
    ];


    public function assistantScheduleProfile()
    {
        return $this->belongsTo(AssistantScheduleProfile::class);
    }

    public function assistantScheduleShiftDays()
    {
        return $this->hasMany(AssistantScheduleShiftDay::class);
    }

    public function shiftDays()
    {
        return $this->hasMany(AssistantScheduleShiftDay::class);
    }
}

