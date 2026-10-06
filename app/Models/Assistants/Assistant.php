<?php

namespace App\Models\Assistants;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\SoftDeletes;
use App\Models\Auth\User;
use App\Models\Schedules\Schedule;
use App\Models\Absences\Absence;
use App\Models\Schedules\AssistantScheduleProfile;
use App\Models\Schools\School;
use Database\Factories\AssistantFactory;
use Illuminate\Database\Eloquent\Attributes\UseFactory;

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
        'emergency_contact_name',
        'emergency_contact_phone',
        'emergency_contact_kinship',
        'available_for_transfer',
    ];

    protected function casts(): array
    {
        return [
            'birth_date' => 'date:Y-m-d',
            'admission_date' => 'date:Y-m-d',
            'criminal_record_expiry' => 'date:Y-m-d',
            'available_for_transfer' => 'boolean',
        ];
    }

    public function user()
    {
        return $this->belongsTo(User::class);
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

