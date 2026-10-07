<?php

namespace App\Http\Resources\Assistants;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AssistantExceptionResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => (string) $this->id,
            'assistant_id' => (string) $this->assistant_id,
            'type' => $this->type,
            'description' => $this->description,
            'valid_from' => $this->valid_from ? $this->valid_from->format('Y-m-d') : null,
            'valid_until' => $this->valid_until ? $this->valid_until->format('Y-m-d') : null,
            'start_time' => $this->start_time ? $this->start_time->format('H:i') : null,
            'end_time' => $this->end_time ? $this->end_time->format('H:i') : null,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
