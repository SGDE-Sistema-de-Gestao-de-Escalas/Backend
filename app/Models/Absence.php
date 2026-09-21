<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Absence extends Model
{
    /** @use HasFactory<\Database\Factories\AbsenceFactory> */
    use HasFactory;

    public function assistant()
    {
        return $this->belongsTo(Assistant::class);
    }

    public function absenceType()
    {
        return $this->belongsTo(AbsenceType::class);
    }

}
