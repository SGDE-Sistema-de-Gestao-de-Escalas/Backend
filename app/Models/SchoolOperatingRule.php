<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUuids;

class SchoolOperatingRule extends Model
{
    /** @use HasFactory<\Database\Factories\SchoolOperatingRuleFactory> */
    use HasFactory, HasUuids;

    protected $fillable = [
        'school_id',
        'name',
        'description',
    ];

    public function school()
    {
        return $this->belongsTo(School::class);
    }

    public function schoolOperatingRuleDays()
    {
        return $this->hasMany(SchoolOperatingRuleDay::class);
    }
}

