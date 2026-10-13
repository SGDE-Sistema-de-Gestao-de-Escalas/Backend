<?php

namespace App\Models\Schedules;

use App\Models\Assistants\Assistant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

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

    public function assistant(): BelongsTo
    {
        return $this->belongsTo(Assistant::class);
    }

    public function assistantScheduleShifts(): HasMany
    {
        return $this->hasMany(AssistantScheduleShift::class);
    }

    public function shifts(): HasMany
    {
        return $this->hasMany(AssistantScheduleShift::class);
    }
}
