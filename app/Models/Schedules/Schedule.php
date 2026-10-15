<?php

namespace App\Models\Schedules;

use App\Models\Assistants\Assistant;
use App\Models\Schools\School;
use Database\Factories\ScheduleFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Schedule extends Model
{
    /** @use HasFactory<ScheduleFactory> */
    use HasFactory, HasUuids, SoftDeletes;

    protected $fillable = [
        'school_id',
        'assistant_id',
        'week_start',
        'week_end',
        'assistant_schedule_profile_id',
    ];

    // Lifecycle fields are assigned explicitly by backend services, not mass assignment.
    protected function casts(): array
    {
        return [
            'week_start' => 'date:Y-m-d',
            'week_end' => 'date:Y-m-d',
            'generated_at' => 'datetime',
            'published_at' => 'datetime',
        ];
    }

    public function assistantScheduleProfile()
    {
        return $this->belongsTo(AssistantScheduleProfile::class);
    }

    public function school()
    {
        return $this->belongsTo(School::class);
    }

    public function assistant()
    {
        return $this->belongsTo(Assistant::class);
    }

    public function scheduleEntries()
    {
        return $this->hasMany(ScheduleEntry::class);
    }
}
