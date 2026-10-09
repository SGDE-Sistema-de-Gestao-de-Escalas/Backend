<?php

namespace App\Models\Assistants;

use App\Models\Absences\Absence;
use App\Models\Auth\User;
use App\Models\Schedules\AssistantScheduleProfile;
use App\Models\Schedules\Schedule;
use App\Models\Schools\School;
use Database\Factories\AssistantFactory;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

#[UseFactory(AssistantFactory::class)]
class Assistant extends Model
{
    /** @use HasFactory<\Database\Factories\AssistantFactory> */
    use HasFactory, HasUuids, SoftDeletes;

    protected $fillable = [
        'user_id',
        'school_id',
        'internal_number',
        'phone',
        'nif',
        'social_security_number',
        'birth_date',
        'admission_date',
        'criminal_record_path',
        'criminal_record_expiry',
        'address_street',
        'address_zip_code',
        'address_city',
        'emergency_contact_name',
        'emergency_contact_phone',
        'emergency_contact_kinship',
        'available_for_transfer',
        'is_anonymized',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'birth_date' => 'date:Y-m-d',
            'admission_date' => 'date:Y-m-d',
            'criminal_record_expiry' => 'date:Y-m-d',
            'available_for_transfer' => 'boolean',
            'is_anonymized' => 'boolean',
        ];
    }

    /**
     * Scope para o Motor de Horários: apenas assistentes ativos e não-anonimizados.
     */
    public function scopeForScheduleGeneration($query)
    {
        return $query->whereHas('user', function ($q) {
            $q->where('is_active', true);
        })->where(function ($q) {
            $q->where('is_anonymized', false)
              ->orWhereNull('is_anonymized');
        });
    }

    public function user()
    {
        // withTrashed para continuar a mostrar o nome de assistentes apagados (histórico).
        return $this->belongsTo(User::class)->withTrashed();
    }

    public function school()
    {
        return $this->belongsTo(School::class);
    }

    public function assistantExceptions()
    {
        return $this->hasMany(AssistantException::class);
    }

    public function schedules()
    {
        return $this->hasMany(Schedule::class);
    }

    public function absences()
    {
        return $this->hasMany(Absence::class);
    }

    public function assistantTemporaryAssignments()
    {
        return $this->hasMany(AssistantTemporaryAssignments::class);
    }

    public function assistantScheduleProfiles()
    {
        return $this->hasMany(AssistantScheduleProfile::class);
    }
}
