<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Str;

class AssistantResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $isAnonymized = (bool) ($this->is_anonymized ?? false);
        $firstName = $isAnonymized ? 'Assistente' : ($this->user?->first_name ?? '');
        $lastName = $isAnonymized ? 'Anonimizado' : ($this->user?->last_name ?? '');
        $name = $isAnonymized ? 'Assistente Anonimizado' : ($this->user?->name ?? trim("{$firstName} {$lastName}") ?: 'Assistente');

        return [
            'id' => $this->id,
            'user_id' => $this->user_id,
            'school_id' => $this->school_id,

            'first_name' => $firstName,
            'last_name' => $lastName,
            'name' => $name,
            'initials' => $isAnonymized ? 'AA' : (Str::upper(Str::substr((string) $firstName, 0, 1).Str::substr((string) $lastName, 0, 1)) ?: 'AS'),
            'is_active' => (bool) ($this->user?->is_active ?? false) && ! $this->trashed() && ! $isAnonymized,
            'is_anonymized' => (bool) ($this->is_anonymized || ($this->user?->anonymized_at !== null)),
            'anonymized_at' => $this->user?->anonymized_at?->toIso8601String(),

            'internal_number' => $this->internal_number ?? '',
            'email' => $isAnonymized ? 'anonimizado@sistema.local' : ($this->user?->email ?? ''),
            'phone' => $isAnonymized ? '-' : ($this->phone ?? '-'),
            'nif' => $isAnonymized ? '---------' : ($this->nif ?? '-'),
            'social_security_number' => $isAnonymized ? '-----------' : ($this->social_security_number ?? '-'),
            'birth_date' => $this->birth_date?->format('Y-m-d') ?? '',
            'admission_date' => $this->admission_date?->format('Y-m-d') ?? '',

            'has_criminal_record' => ! $isAnonymized && $this->criminal_record_path !== null,
            'criminal_record_expiry' => $isAnonymized ? null : $this->criminal_record_expiry?->format('Y-m-d'),

            'address_street' => $isAnonymized ? '-' : ($this->address_street ?? '-'),
            'address_zip_code' => $isAnonymized ? '-' : ($this->address_zip_code ?? '-'),
            'address_city' => $isAnonymized ? '-' : ($this->address_city ?? '-'),

            'emergency_contact_name' => $isAnonymized ? '-' : ($this->emergency_contact_name ?? '-'),
            'emergency_contact_phone' => $isAnonymized ? '-' : ($this->emergency_contact_phone ?? '-'),
            'emergency_contact_kinship' => $isAnonymized ? '-' : ($this->emergency_contact_kinship ?? '-'),

            'available_for_transfer' => ! $isAnonymized && (bool) ($this->available_for_transfer ?? false),

            'school' => $this->whenLoaded('school', fn () => [
                'id' => $this->school->id,
                'name' => $this->school->name,
                'acronym' => $this->school->acronym,
            ]),

            'schedule_profiles' => $this->whenLoaded('assistantScheduleProfiles', fn () => $this->assistantScheduleProfiles->map(fn ($profile) => [
                'id' => $profile->id,
                'type' => $profile->type,
                'rotation_period' => $profile->rotation_period,
                'starts_with' => $profile->starts_with,
                'valid_from' => is_string($profile->valid_from) ? $profile->valid_from : $profile->valid_from?->format('Y-m-d'),
                'valid_until' => is_string($profile->valid_until) ? $profile->valid_until : $profile->valid_until?->format('Y-m-d'),
                'shifts' => $profile->shifts->map(fn ($shift) => [
                    'id' => $shift->id,
                    'shift_label' => $shift->shift_label,
                    'entry_time' => is_string($shift->entry_time) ? substr($shift->entry_time, 0, 5) : $shift->entry_time,
                    'exit_time' => is_string($shift->exit_time) ? substr($shift->exit_time, 0, 5) : $shift->exit_time,
                    'lunch_enabled' => (bool) $shift->lunch_enabled,
                    'lunch_start' => is_string($shift->lunch_start) ? substr($shift->lunch_start, 0, 5) : $shift->lunch_start,
                    'lunch_end' => is_string($shift->lunch_end) ? substr($shift->lunch_end, 0, 5) : $shift->lunch_end,
                    'lunch_duration_minutes' => $shift->lunch_duration_minutes,
                    'days' => $shift->shiftDays->pluck('day_of_week')->values()->all(),
                ]),
            ])),

            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
            'deleted_at' => $this->deleted_at,
        ];
    }
}
