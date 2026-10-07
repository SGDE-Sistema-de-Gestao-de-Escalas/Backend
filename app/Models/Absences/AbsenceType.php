<?php

namespace App\Models\Absences;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUuids;

class AbsenceType extends Model
{
    /** @use HasFactory<\Database\Factories\AbsenceTypeFactory> */
    use HasFactory, HasUuids;

    protected $fillable = [
        'name',
        'requires_document',
    ];

    protected function casts(): array
    {
        return [
            'requires_document' => 'boolean',
        ];
    }

    public function absences()
    {
        return $this->hasMany(Absence::class);
    }
}
