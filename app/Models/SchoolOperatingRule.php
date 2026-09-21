<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class SchoolOperatingRule extends Model
{
    /** @use HasFactory<\Database\Factories\SchoolOperatingRuleFactory> */
    use HasFactory;

    public function school()
    {
        return $this->belongsTo(School::class);
    }

    public function schoolOperatingRuleDays()
    {
        return $this->hasMany(SchoolOperatingRuleDay::class);
    }
}
