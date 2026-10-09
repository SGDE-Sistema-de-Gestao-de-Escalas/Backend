<?php

namespace App\Http\Resources\Schedules;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AssistantScheduleProfileResource extends JsonResource
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
            'rotation_period' => $this->rotation_period,
            'starts_with' => $this->starts_with,
            'valid_from' => is_string($this->valid_from) ? $this->valid_from : $this->valid_from?->format('Y-m-d'),
            'valid_until' => is_string($this->valid_until) ? $this->valid_until : $this->valid_until?->format('Y-m-d'),
            'shifts' => $this->whenLoaded('shifts', function () {
                return $this->shifts->map(function ($shift) {
                    return [
                        'id' => (string) $shift->id,
                        'shift_label' => $shift->shift_label,
                        'entry_time' => is_string($shift->entry_time) ? substr($shift->entry_time, 0, 5) : $shift->entry_time,
                        'exit_time' => is_string($shift->exit_time) ? substr($shift->exit_time, 0, 5) : $shift->exit_time,
                        'lunch_enabled' => (bool) $shift->lunch_enabled,
                        'lunch_start' => is_string($shift->lunch_start) ? substr($shift->lunch_start, 0, 5) : $shift->lunch_start,
                        'lunch_end' => is_string($shift->lunch_end) ? substr($shift->lunch_end, 0, 5) : $shift->lunch_end,
                        'lunch_duration_minutes' => $shift->lunch_duration_minutes,
                        'days' => $shift->relationLoaded('shiftDays')
                            ? $shift->shiftDays->pluck('day_of_week')->values()->all()
                            : [],
                    ];
                });
            }),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}

