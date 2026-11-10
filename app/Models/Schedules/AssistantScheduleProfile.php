<?php

namespace App\Models\Schedules;

use App\Models\Assistants\Assistant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AssistantScheduleProfile extends Model
{
    use HasUuids;

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

    public function assistant(): BelongsTo
    {
        return $this->belongsTo(Assistant::class);
    }

    public function shifts(): HasMany
    {
        return $this->hasMany(AssistantScheduleShift::class);
    }
}
