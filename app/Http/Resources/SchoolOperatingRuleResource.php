<?php

namespace App\Http\Resources;

use App\Services\SchoolOperatingRuleService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class SchoolOperatingRuleResource extends JsonResource
{
    private const STATUS_MESSAGES = [
        SchoolOperatingRuleService::STATUS_UPCOMING => 'Versão futura',
        SchoolOperatingRuleService::STATUS_CURRENT => 'Versão em vigor',
        SchoolOperatingRuleService::STATUS_SUPERSEDED => 'Versão substituída',
    ];

    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $service = app(SchoolOperatingRuleService::class);

        $status = $service->status($this->resource);
        $deletionBlockReason = $service->deletionBlockReason($this->resource);

        return [
            'id' => $this->id,
            'school_id' => $this->school_id,
            'valid_from' => $this->valid_from?->toDateString(),
            'valid_until' => $service->validUntil($this->resource),
            'days' => $this->schoolOperatingRuleDays
                ->sortBy('day_of_week')
                ->values()
                ->map(fn ($day) => [
                    'day_of_week' => (int) $day->day_of_week,
                    'opening_time' => substr((string) $day->opening_time, 0, 5),
                    'closing_time' => substr((string) $day->closing_time, 0, 5),
                    'lunch_start' => substr((string) $day->lunch_start, 0, 5),
                    'lunch_end' => substr((string) $day->lunch_end, 0, 5),
                    'lunch_duration_minutes' => (int) $day->lunch_duration_minutes,
                ]),
            'status' => $status,
            'status_message' => self::STATUS_MESSAGES[$status],
            'can_edit' => $status === SchoolOperatingRuleService::STATUS_UPCOMING,
            'can_delete' => $deletionBlockReason === null,
            'cannot_delete_reason' => $deletionBlockReason,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
