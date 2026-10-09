<?php

namespace App\Models\Schedules;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUuids;

class AssistantScheduleProfile extends Model
{
    /** @use HasFactory<\Database\Factories\AssistantScheduleProfileFactory> */
    use HasFactory, HasUuids;

    protected $fillable = [
        'assistant_id',
        'type',
        'rotation_period',
        'starts_with',
        'valid_from',
        'valid_until',
    ];


    public function assistant()
    {
        return $this->belongsTo(\App\Models\Assistants\Assistant::class);
    }

    public function assistantScheduleShifts()
    {
        return $this->hasMany(AssistantScheduleShift::class);
    }

    public function shifts()
    {
        return $this->hasMany(AssistantScheduleShift::class);
    }
}

