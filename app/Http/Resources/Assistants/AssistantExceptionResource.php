<?php

namespace App\Http\Resources\Assistants;

use App\Services\AssistantExceptionService;
use Carbon\Carbon;
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
        $service = app(AssistantExceptionService::class);
        $today = Carbon::today();
        $validFrom = $this->valid_from ? Carbon::parse($this->valid_from)->startOfDay() : null;
        $validUntil = $this->valid_until ? Carbon::parse($this->valid_until)->startOfDay() : null;

        $hasPastSchedules = $service->hasPastSchedules($this->resource);
        $futureCheck = $service->checkFutureSchedulesWarning($this->resource);

        if ($validFrom && $validFrom->isAfter($today)) {
            $status = 'upcoming';
            $canEdit = true;
            $canChangeEndDate = true;
            $canDelete = true;
            $cannotEditReason = null;
            $cannotDeleteReason = null;
        } elseif ($validUntil && $validUntil->isBefore($today)) {
            $status = 'superseded';
            $canEdit = false;
            $canChangeEndDate = false;
            $canDelete = false;
            $cannotEditReason = 'Não é possível editar uma exceção cuja vigência já terminou no passado.';
            $cannotDeleteReason = 'Não é possível eliminar uma exceção cuja vigência já terminou no passado.';
        } else {
            $status = 'current';
            $canEdit = true;
            $canChangeEndDate = true;
            $canDelete = ! $hasPastSchedules;
            $cannotEditReason = null;
            $cannotDeleteReason = $hasPastSchedules
                ? 'Não é possível eliminar esta exceção porque já existem escalas passadas geradas sob a sua vigência. Defina uma data de fim para dar esta exceção como terminada.'
                : null;
        }

        return [
            'id' => (string) $this->id,
            'assistant_id' => (string) $this->assistant_id,
            'type' => $this->type,
            'description' => $this->description,
            'valid_from' => $this->valid_from ? Carbon::parse($this->valid_from)->format('Y-m-d') : null,
            'valid_until' => $this->valid_until ? Carbon::parse($this->valid_until)->format('Y-m-d') : null,
            'start_time' => $this->start_time ? Carbon::parse($this->start_time)->format('H:i') : null,
            'end_time' => $this->end_time ? Carbon::parse($this->end_time)->format('H:i') : null,
            'days' => $this->days ? array_values(array_map('intval', $this->days)) : [],
            'lunch_enabled' => (bool) $this->lunch_enabled,
            'lunch_start' => $this->lunch_start ? Carbon::parse($this->lunch_start)->format('H:i') : null,
            'lunch_end' => $this->lunch_end ? Carbon::parse($this->lunch_end)->format('H:i') : null,
            'lunch_duration_minutes' => $this->lunch_duration_minutes !== null ? (int) $this->lunch_duration_minutes : null,
            'status' => $status,
            'can_edit' => $canEdit,
            'can_change_end_date' => $canChangeEndDate,
            'can_delete' => $canDelete,
            'cannot_edit_reason' => $cannotEditReason,
            'cannot_delete_reason' => $cannotDeleteReason,
            'has_past_schedules' => $hasPastSchedules,
            'has_future_schedules' => $futureCheck['has_future_schedules'],
            'draft_days_count' => $futureCheck['draft_days_count'],
            'published_days_count' => $futureCheck['published_days_count'],
            'delete_warning' => $futureCheck['warning'],
            'assistant' => $this->whenLoaded('assistant'),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
