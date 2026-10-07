<?php

namespace App\Http\Resources;

use App\Services\ActivityTypeService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ActivityTypeResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $deletionBlockReason = app(ActivityTypeService::class)->deletionBlockReason($this->resource);

        return [
            'id' => $this->id,
            'school_id' => $this->school_id,
            'name' => $this->name,
            'color' => $this->color,
            'is_system' => $this->is_system,
            'active' => $this->active,
            'status_message' => $this->active ? 'Atividade ativa' : 'Atividade inativa',
            'can_delete' => $deletionBlockReason === null,
            'cannot_delete_reason' => $deletionBlockReason,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
