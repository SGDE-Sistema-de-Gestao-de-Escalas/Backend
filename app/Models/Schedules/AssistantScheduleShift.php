<?php

namespace App\Models\Schedules;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class AssistantScheduleShift extends Model
{
    use HasUuids;

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

    protected function casts(): array
    {
        return [
            'lunch_enabled' => 'boolean',
            'lunch_duration_minutes' => 'integer',
        ];
    }

    public function assistantScheduleProfile()
    {
        return $this->belongsTo(AssistantScheduleProfile::class);
    }

    public function shiftDays()
    {
        return $this->hasMany(AssistantScheduleShiftDay::class);
    }
}
