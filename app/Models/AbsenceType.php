<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class AbsenceType extends Model
{
    /** @use HasFactory<\Database\Factories\AbsenceTypeFactory> */
    use HasFactory;

    protected $fillable = [
        'name',
        'description',
    ];

    public function absences()
    {
        return $this->hasMany(Absence::class);
    }
}
