<?php

namespace App\Models\Schedules;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class AssistantScheduleShiftDay extends Model
{
    use HasUuids;

    protected $fillable = [
        'assistant_schedule_shift_id',
        'day_of_week',
    ];

    protected function casts(): array
    {
        return ['day_of_week' => 'integer'];
    }

    public function assistantScheduleShift()
    {
        return $this->belongsTo(AssistantScheduleShift::class);
    }
}
