<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ScheduleRuleResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'school_id' => $this->school_id,
            'activity_type_id' => $this->activity_type_id,
            'period_start' => substr($this->period_start, 0, 5),
            'period_end' => substr($this->period_end, 0, 5),
            'min' => $this->min,
            'type' => $this->type,
            'valid_from' => $this->valid_from->format('Y-m-d'),
            'valid_until' => $this->valid_until?->format('Y-m-d'),
            'days_of_week' => $this->days_of_week ?? [],
            'assistant_ids' => $this->assistants->modelKeys(),
            'assistants' => $this->assistants->map(fn ($assistant) => [
                'id' => $assistant->id,
                'name' => ($assistant->is_anonymized || $assistant->user?->anonymized_at)
                    ? 'Assistente Anonimizado' : ($assistant->user?->name ?? 'Assistente'),
                'priority' => (int) $assistant->pivot->priority,
            ])->values(),
        ];
    }
}
