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
        'name',
        'description',
    ];

    public function assistant()
    {
        return $this->belongsTo(Assistant::class);
    }

    public function assistantScheduleShifts()
    {
        return $this->hasMany(AssistantScheduleShift::class);
    }
}

