<?php

namespace App\Http\Resources;

use App\Services\SchoolService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class SchoolResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $deletionBlockReason = app(SchoolService::class)->deletionBlockReason($this->resource);

        return [
            'id' => $this->id,
            'name' => $this->name,
            'acronym' => $this->acronym,
            'address' => $this->address,
            'phone' => $this->phone,
            'email' => $this->email,
            'active' => $this->active,
            'assistants' => $this->assistants_count ?? $this->assistants()->count(),
            'assistants_count' => $this->assistants_count ?? $this->assistants()->count(),
            'can_delete' => $deletionBlockReason === null,
            'cannot_delete_reason' => $deletionBlockReason,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
