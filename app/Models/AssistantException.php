<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUuids;

class AssistantException extends Model
{
    /** @use HasFactory<\Database\Factories\AssistantExceptionFactory> */
    use HasFactory, HasUuids;

    protected $fillable = [
        'assistant_id',
        'start_date',
        'end_date',
        'reason',
    ];

    public function assistant()
    {
        return $this->belongsTo(Assistant::class);
    }
}

