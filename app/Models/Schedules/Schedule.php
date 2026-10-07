<?php

namespace App\Models\Schedules;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\SoftDeletes;

class Schedule extends Model
{
    /** @use HasFactory<\Database\Factories\ScheduleFactory> */
    use HasFactory, HasUuids, SoftDeletes;

    protected $fillable = [
        'school_id',
        'assistant_id',
        'start_date',
        'end_date',
    ];

    public function school()
    {
        return $this->belongsTo(School::class);
    }

    public function assistant()
    {
        return $this->belongsTo(Assistant::class);
    }

    public function scheduleEntries()
    {
        return $this->hasMany(ScheduleEntry::class);
    }
}

