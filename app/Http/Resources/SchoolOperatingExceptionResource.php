<?php

namespace App\Http\Resources;

use App\Services\SchoolOperatingExceptionService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class SchoolOperatingExceptionResource extends JsonResource
{
    private const STATUS_MESSAGES = [
        SchoolOperatingExceptionService::STATUS_UPCOMING => 'Exceção futura',
        SchoolOperatingExceptionService::STATUS_ACTIVE => 'Exceção em curso',
        SchoolOperatingExceptionService::STATUS_ENDED => 'Exceção terminada',
    ];

    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $service = app(SchoolOperatingExceptionService::class);

        $status = $service->status($this->resource);
        $deletionBlockReason = $service->deletionBlockReason($this->resource);

        return [
            'id' => $this->id,
            'school_id' => $this->school_id,
            'name' => $this->name,
            'is_closed' => (bool) $this->is_closed,
            'opening_time' => $this->time($this->opening_time),
            'closing_time' => $this->time($this->closing_time),
            'lunch_start' => $this->time($this->lunch_start),
            'lunch_end' => $this->time($this->lunch_end),
            'lunch_duration_minutes' => $this->lunch_duration_minutes === null
                ? null
                : (int) $this->lunch_duration_minutes,
            'valid_from' => $this->valid_from?->toDateString(),
            'valid_until' => $this->valid_until?->toDateString(),
            'status' => $status,
            'status_message' => self::STATUS_MESSAGES[$status],
            'can_edit_all' => $service->canEditAll($this->resource),
            'can_change_end_date' => $service->canChangeEndDate($this->resource),
            'can_delete' => $deletionBlockReason === null,
            'cannot_delete_reason' => $deletionBlockReason,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }

    private function time(mixed $value): ?string
    {
        return $value === null ? null : substr((string) $value, 0, 5);
    }
}
