<?php

namespace App\Models\Schedules;

use App\Models\Assistants\Assistant;
use Database\Factories\AssistantScheduleProfileFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class AssistantScheduleProfile extends Model
{
    /** @use HasFactory<AssistantScheduleProfileFactory> */
    use HasFactory, HasUuids;

    protected $fillable = [
        'assistant_id',
        'type',
        'rotation_period',
        'starts_with',
        'valid_from',
        'valid_until',
    ];

    protected function casts(): array
    {
        return [
            'valid_from' => 'date:Y-m-d',
            'valid_until' => 'date:Y-m-d',
        ];
    }

    public function assistant()
    {
        return $this->belongsTo(Assistant::class);
    }

    public function assistantScheduleShifts()
    {
        return $this->hasMany(AssistantScheduleShift::class);
    }
}
