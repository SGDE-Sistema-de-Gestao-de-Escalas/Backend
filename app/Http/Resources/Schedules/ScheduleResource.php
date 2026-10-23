<?php

namespace App\Http\Resources\Schedules;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ScheduleResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $assistant = $this->whenLoaded('assistant');
        $assistantName = null;
        if ($assistant) {
            $assistantName = ($assistant->is_anonymized || $assistant->user?->anonymized_at)
                ? 'Assistente Anonimizado'
                : trim(($assistant->user?->first_name ?? '').' '.($assistant->user?->last_name ?? ''));
        }

        return [
            'id' => $this->id,
            'school_id' => $this->school_id,
            'assistant_id' => $this->assistant_id,
            'assistant_name' => $assistantName,
            'assistant_schedule_profile_id' => $this->assistant_schedule_profile_id,
            'week_start' => $this->week_start?->format('Y-m-d'),
            'week_end' => $this->week_end?->format('Y-m-d'),
            'status' => $this->status,
            'generated_at' => $this->generated_at?->toIso8601String(),
            'published_at' => $this->published_at?->toIso8601String(),
            'entries' => ScheduleEntryResource::collection($this->whenLoaded('scheduleEntries')),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
