<?php

namespace App\Http\Resources\Schedules;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ScheduleEntryResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'schedule_id' => $this->schedule_id,
            'activity_type_id' => $this->activity_type_id,
            'activity_name' => $this->activity_name ?? $this->activityType?->name,
            'activity_color' => $this->activityType?->color,
            'is_fallback' => (bool) ($this->activityType?->is_fallback ?? false),
            'schedule_rule_id' => $this->schedule_rule_id,
            'date' => is_string($this->date) ? substr($this->date, 0, 10) : $this->date?->format('Y-m-d'),
            'start_time' => substr((string) $this->start_time, 0, 5),
            'end_time' => substr((string) $this->end_time, 0, 5),
            'is_locked' => (bool) $this->is_locked,
            'replaced_assistant_id' => $this->replaced_assistant_id,
            'replacement_reason' => $this->replacement_reason,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
