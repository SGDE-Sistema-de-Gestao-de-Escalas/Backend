<?php

namespace App\Http\Resources\Schedules;

use Carbon\Carbon;
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
        $today = Carbon::today();
        $validFrom = $this->valid_from ? Carbon::parse($this->valid_from)->startOfDay() : null;
        $validUntil = $this->valid_until ? Carbon::parse($this->valid_until)->startOfDay() : null;

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
            $cannotEditReason = 'Não é possível editar um horário passado que já regeu escalas.';
            $cannotDeleteReason = 'Não é possível eliminar um horário passado que já regeu escalas passadas.';
        } else {
            $status = 'current';
            $canEdit = false;
            $canChangeEndDate = false;
            $canDelete = false;
            $cannotEditReason = 'Não é possível editar um horário em vigor; o assistente tem de ter sempre um horário ativo.';
            $cannotDeleteReason = 'Não é possível eliminar o horário em vigor; o assistente tem de ter sempre um horário ativo.';
        }

        return [
            'id' => (string) $this->id,
            'assistant_id' => (string) $this->assistant_id,
            'type' => $this->type,
            'rotation_period' => $this->rotation_period,
            'starts_with' => $this->starts_with,
            'valid_from' => $this->valid_from ? Carbon::parse($this->valid_from)->format('Y-m-d') : null,
            'valid_until' => $this->valid_until ? Carbon::parse($this->valid_until)->format('Y-m-d') : null,
            'status' => $status,
            'can_edit' => $canEdit,
            'can_change_end_date' => $canChangeEndDate,
            'can_delete' => $canDelete,
            'cannot_edit_reason' => $cannotEditReason,
            'cannot_delete_reason' => $cannotDeleteReason,
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
