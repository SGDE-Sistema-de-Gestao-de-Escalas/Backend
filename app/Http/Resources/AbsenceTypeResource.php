<?php

namespace App\Http\Resources;

use App\Services\AbsenceTypeService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AbsenceTypeResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $deletionBlockReason = app(AbsenceTypeService::class)->deletionBlockReason($this->resource);

        return [
            'id' => $this->id,
            'name' => $this->name,
            'requires_document' => $this->requires_document,
            'can_delete' => $deletionBlockReason === null,
            'cannot_delete_reason' => $deletionBlockReason,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
