<?php

namespace App\Models\Schedules;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class ScheduleRecalculationFlag extends Model
{
    use HasUuids;

    public const REASON_TRANSFER_REMOVED = 'transfer_removed';

    protected $fillable = [
        'school_id',
        'assistant_id',
        'date',
        'reason',
        'message',
    ];

    protected function casts(): array
    {
        return [
            'date' => 'date:Y-m-d',
        ];
    }
}
