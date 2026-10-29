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
        $isTemporaryTransfer = false;
        $originSchoolId = null;
        $originSchoolName = null;

        if ($assistant) {
            $assistantName = ($assistant->is_anonymized || $assistant->user?->anonymized_at)
                ? 'Assistente Anonimizado'
                : trim(($assistant->user?->first_name ?? '').' '.($assistant->user?->last_name ?? ''));

            if ($this->school_id && $assistant->school_id && ((string) $this->school_id !== (string) $assistant->school_id)) {
                $isTemporaryTransfer = true;
                $originSchoolId = $assistant->school_id;
                $originSchoolName = $assistant->school?->name;
            }
        }

        return [
            'id' => $this->id,
            'school_id' => $this->school_id,
            'school_name' => $this->school?->name,
            'assistant_id' => $this->assistant_id,
            'assistant_name' => $assistantName,
            'is_temporary_transfer' => $isTemporaryTransfer,
            'origin_school_id' => $originSchoolId,
            'origin_school_name' => $originSchoolName,
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
