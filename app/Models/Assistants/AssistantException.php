<?php

namespace App\Models\Assistants;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\SoftDeletes;

class AssistantException extends Model
{
    /** @use HasFactory<\Database\Factories\AssistantExceptionFactory> */
    use HasFactory, HasUuids, SoftDeletes;

    protected $fillable = [
        'assistant_id',
        'type',
        'description',
        'valid_from',
        'valid_until',
        'start_time',
        'end_time',
        'days',
        'lunch_enabled',
        'lunch_start',
        'lunch_end',
        'lunch_duration_minutes',
    ];

    protected $casts = [
        'valid_from' => 'date',
        'valid_until' => 'date',
        'start_time' => 'datetime:H:i',
        'end_time' => 'datetime:H:i',
        'days' => 'array',
        'lunch_enabled' => 'boolean',
        'lunch_start' => 'datetime:H:i',
        'lunch_end' => 'datetime:H:i',
        'lunch_duration_minutes' => 'integer',
    ];

    public function assistant()
    {
        return $this->belongsTo(Assistant::class);
    }
}

