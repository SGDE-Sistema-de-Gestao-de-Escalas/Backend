<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUuids;

class AssistantScheduleShiftDay extends Model
{
    /** @use HasFactory<\Database\Factories\AssistantScheduleShiftDayFactory> */
    use HasFactory, HasUuids;

    protected $fillable = [
        'assistant_schedule_shift_id',
        'day_of_week',
        'start_time',
        'end_time',
    ];

    public function assistantScheduleShift()
    {
        return $this->belongsTo(AssistantScheduleShift::class);
    }
}

