<?php

namespace App\Models\Schedules;

use App\Models\System\ActivityType;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUuids;

class ScheduleEntry extends Model
{
    /** @use HasFactory<\Database\Factories\ScheduleEntryFactory> */
    use HasFactory, HasUuids;

    protected $fillable = [
        'schedule_id',
        'activity_type_id',
        'activity_name',
        'date',
        'start_time',
        'end_time',
        'is_locked',
    ];

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
}
