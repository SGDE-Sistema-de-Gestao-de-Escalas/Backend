<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class AssistantScheduleShiftDay extends Model
{
    /** @use HasFactory<\Database\Factories\AssistantScheduleShiftDayFactory> */
    use HasFactory;

    public function assistantScheduleShift()
    {
        return $this->belongsTo(AssistantScheduleShift::class);
    }
}
