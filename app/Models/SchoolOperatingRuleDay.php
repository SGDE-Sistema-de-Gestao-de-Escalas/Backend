<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUuids;

class SchoolOperatingRuleDay extends Model
{
    /** @use HasFactory<\Database\Factories\SchoolOperatingRuleDayFactory> */
    use HasFactory, HasUuids;

    protected $fillable = [
        'school_operating_rule_id',
        'day_of_week',
        'start_time',
        'end_time',
    ];

    public function schoolOperatingRule()
    {
        return $this->belongsTo(SchoolOperatingRule::class);
    }
}

