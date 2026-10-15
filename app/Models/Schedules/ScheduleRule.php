<?php

namespace App\Models\Schedules;

use App\Models\Assistants\Assistant;
use App\Models\Schools\School;
use App\Models\System\ActivityType;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class ScheduleRule extends Model
{
    use HasUuids;

    protected $fillable = [
        'school_id',
        'activity_type_id',
        'period_start',
        'period_end',
        'min',
        'type',
        'valid_from',
        'valid_until',
        'days_of_week',
    ];

    protected function casts(): array
    {
        return [
            'min' => 'integer',
            'valid_from' => 'date:Y-m-d',
            'valid_until' => 'date:Y-m-d',
            'days_of_week' => 'array',
        ];
    }

    public function school(): BelongsTo
    {
        return $this->belongsTo(School::class);
    }

    public function activityType(): BelongsTo
    {
        return $this->belongsTo(ActivityType::class);
    }

    public function assistants(): BelongsToMany
    {
        return $this->belongsToMany(Assistant::class, 'schedule_rule_assistant')
            ->withPivot('priority')
            ->orderByPivot('priority');
    }
}
