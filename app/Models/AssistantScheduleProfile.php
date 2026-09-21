<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class AssistantScheduleProfile extends Model
{
    /** @use HasFactory<\Database\Factories\AssistantScheduleProfileFactory> */
    use HasFactory;

    public function assistant()
    {
        return $this->belongsTo(Assistant::class);
    }

    public function assistantScheduleShifts()
    {
        return $this->hasMany(AssistantScheduleShift::class);
    }
}
