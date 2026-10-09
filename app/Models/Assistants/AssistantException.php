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
    ];

    protected $casts = [
        'valid_from' => 'date',
        'valid_until' => 'date',
        'start_time' => 'datetime:H:i',
        'end_time' => 'datetime:H:i',
    ];

    public function assistant()
    {
        return $this->belongsTo(Assistant::class);
    }
}

