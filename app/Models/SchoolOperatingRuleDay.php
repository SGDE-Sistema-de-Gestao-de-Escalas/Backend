<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class SchoolOperatingRuleDay extends Model
{
    /** @use HasFactory<\Database\Factories\SchoolOperatingRuleDayFactory> */
    use HasFactory;

    public function schoolOperatingRule()
    {
        return $this->belongsTo(SchoolOperatingRule::class);
    }
}
