<?php

namespace App\Models\Schedules;

use App\Models\Assistants\Assistant;
use App\Models\System\ActivityType;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ScheduleEntry extends Model
{
    /** @use HasFactory<\Database\Factories\ScheduleEntryFactory> */
    use HasFactory, HasUuids;

    protected $fillable = [
        'schedule_id',
        'activity_type_id',
        'activity_name',
        'schedule_rule_id',
        'date',
        'start_time',
        'end_time',
        'is_locked',
        'replaced_assistant_id',
        'replacement_reason',
    ];

    protected function casts(): array
    {
        return [
            'is_locked' => 'boolean',
        ];
    }

    /**
     * O nome da atividade fica gravado na entrada no momento em que ela é
     * criada. Assim, renomear mais tarde um tipo de atividade não altera o
     * histórico (nem os horários já feitos); só as entradas criadas depois
     * passam a ter o novo nome.
     */
    protected static function booted(): void
    {
        static::creating(function (ScheduleEntry $entry) {
            if (blank($entry->activity_name) && $entry->activity_type_id) {
                $entry->activity_name = ActivityType::whereKey($entry->activity_type_id)->value('name');
            }
        });
    }

    public function schedule()
    {
        return $this->belongsTo(Schedule::class);
    }

    public function activityType()
    {
        return $this->belongsTo(ActivityType::class);
    }

    public function scheduleRule()
    {
        return $this->belongsTo(ScheduleRule::class);
    }

    public function replacedAssistant()
    {
        return $this->belongsTo(Assistant::class, 'replaced_assistant_id');
    }
}
