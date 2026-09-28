<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Absence extends Model
{
    /** @use HasFactory<\Database\Factories\AbsenceFactory> */
    use HasFactory;

    protected $fillable = [
        'assistant_id',
        'absence_type_id',
        'start_date',
        'end_date',
        'reason',
    ];

    public function assistant()
    {
        return $this->belongsTo(Assistant::class);
    }

    public function absenceType()
    {
        return $this->belongsTo(AbsenceType::class);
    }

}
