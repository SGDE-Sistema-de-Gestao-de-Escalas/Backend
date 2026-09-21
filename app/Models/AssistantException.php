<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class AssistantException extends Model
{
    /** @use HasFactory<\Database\Factories\AssistantExceptionFactory> */
    use HasFactory;

    public function assistant()
    {
        return $this->belongsTo(Assistant::class);
    }
}
