<?php

namespace App\Services\Assistants;

use App\Models\Assistants\AssistantTemporaryAssignments;
use App\Models\Schedules\ScheduleRecalculationFlag;
use App\Models\Schools\School;
use Carbon\CarbonImmutable;

class TemporaryTransferService
{
    public function clearIncomingForPeriod(string $destinationSchoolId, string $startDate, string $endDate): array
    {
        $start = CarbonImmutable::parse($startDate)->startOfDay();
        $end = CarbonImmutable::parse($endDate)->startOfDay();

        $transfers = AssistantTemporaryAssignments::query()
            ->where('destination_school_id', $destinationSchoolId)
            ->whereDate('valid_from', '<=', $end->toDateString())
            ->where(fn ($q) => $q->whereNull('valid_until')->orWhereDate('valid_until', '>=', $start->toDateString()))
            ->get();

        $removed = [];

        foreach ($transfers as $transfer) {
            $from = CarbonImmutable::parse($transfer->valid_from->format('Y-m-d'))->startOfDay();
            $until = $transfer->valid_until
                ? CarbonImmutable::parse($transfer->valid_until->format('Y-m-d'))->startOfDay()
                : null;

            $removedFrom = $from->greaterThan($start) ? $from : $start;
            $removedUntil = ($until === null || $until->greaterThan($end)) ? $end : $until;

            $keepsBefore = $from->lessThan($start);
            $keepsAfter = $until === null || $until->greaterThan($end);

            if (! $keepsBefore && ! $keepsAfter) {
                $transfer->delete();
            } elseif ($keepsBefore && ! $keepsAfter) {
                $transfer->update(['valid_until' => $start->subDay()->toDateString()]);
            } elseif (! $keepsBefore && $keepsAfter) {
                $transfer->update(['valid_from' => $end->addDay()->toDateString()]);
            } else {
                $tail = $transfer->replicate();
                $tail->valid_from = $end->addDay()->toDateString();
                $tail->valid_until = $until?->toDateString();
                $transfer->update(['valid_until' => $start->subDay()->toDateString()]);
                $tail->save();
            }

            $dates = [];
            for ($day = $removedFrom; $day->lessThanOrEqualTo($removedUntil); $day = $day->addDay()) {
                $dates[] = $day->toDateString();
            }

            $removed[] = [
                'origin_school_id' => (string) $transfer->origin_school_id,
                'destination_school_id' => (string) $transfer->destination_school_id,
                'assistant_id' => (string) $transfer->assistant_id,
                'start_time' => $transfer->start_time ? substr((string) $transfer->start_time, 0, 5) : null,
                'end_time' => $transfer->end_time ? substr((string) $transfer->end_time, 0, 5) : null,
                'dates' => $dates,
            ];
        }

        return $removed;
    }

    public function flagOriginForRecalculation(
        string $originSchoolId,
        string $assistantId,
        string $destinationSchoolId,
        array $dates,
        string $reason,
        ?string $startTime = null,
        ?string $endTime = null,
    ): void {
        $destinationName = School::whereKey($destinationSchoolId)->value('name') ?? 'outra escola';
        $window = $startTime && $endTime ? "{$startTime} - {$endTime}" : 'dia inteiro';

        $message = "A cedência temporária para {$destinationName} ({$window}) foi removida; o assistente volta a estar disponível nesta escola neste dia.";

        foreach ($dates as $date) {
            ScheduleRecalculationFlag::updateOrCreate(
                [
                    'school_id' => $originSchoolId,
                    'assistant_id' => $assistantId,
                    'date' => $date,
                    'reason' => $reason,
                ],
                ['message' => $message]
            );
        }
    }

    public function clearFlagsForPeriod(string $schoolId, string $startDate, string $endDate): void
    {
        ScheduleRecalculationFlag::query()
            ->where('school_id', $schoolId)
            ->whereBetween('date', [$startDate, $endDate])
            ->delete();
    }
}
