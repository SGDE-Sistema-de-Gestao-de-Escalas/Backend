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
        $firstName = $isAnonymized ? 'Assistente' : $this->user?->first_name;
        $lastName = $isAnonymized ? 'Anonimizado' : $this->user?->last_name;

        return [
            'id' => $this->id,
            'user_id' => $this->user_id,
            'school_id' => $this->school_id,

            'first_name' => $firstName,
            'last_name' => $lastName,
            'name' => $isAnonymized ? 'Assistente Anonimizado' : $this->user?->name,
            'initials' => $isAnonymized ? 'AA' : Str::upper(Str::substr((string) $firstName, 0, 1).Str::substr((string) $lastName, 0, 1)),
            'is_active' => (bool) ($this->user?->is_active ?? false) && ! $this->trashed() && ! $isAnonymized,
            'is_anonymized' => $isAnonymized,

            'internal_number' => $this->internal_number,
            'email' => $this->user?->email,
            'phone' => $this->phone,
            'nif' => $this->nif,
            'social_security_number' => $this->social_security_number,
            'birth_date' => $this->birth_date?->format('Y-m-d'),
            'admission_date' => $this->admission_date?->format('Y-m-d'),

            'has_criminal_record' => $this->criminal_record_path !== null,
            'criminal_record_expiry' => $this->criminal_record_expiry?->format('Y-m-d'),

            'address_street' => $this->address_street,
            'address_zip_code' => $this->address_zip_code,
            'address_city' => $this->address_city,

            'emergency_contact_name' => $this->emergency_contact_name,
            'emergency_contact_phone' => $this->emergency_contact_phone,
            'emergency_contact_kinship' => $this->emergency_contact_kinship,

            'available_for_transfer' => $this->available_for_transfer,

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
