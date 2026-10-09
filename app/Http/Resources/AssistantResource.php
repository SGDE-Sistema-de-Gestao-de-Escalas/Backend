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

            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
            'deleted_at' => $this->deleted_at,
        ];
    }
}
