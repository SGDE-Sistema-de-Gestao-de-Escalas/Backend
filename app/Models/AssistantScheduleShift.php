<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class AssistantScheduleShift extends Model
{
    /** @use HasFactory<\Database\Factories\AssistantScheduleShiftFactory> */
    use HasFactory;

    public function assistantScheduleProfile()
    {
        return $this->belongsTo(AssistantScheduleProfile::class);
    }

    public function assistantScheduleShiftDays()
    {
        return $this->hasMany(AssistantScheduleShiftDay::class);
    }
}
