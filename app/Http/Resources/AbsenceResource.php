<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class AbsenceResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $assistant = $this->assistant;
        $user = $assistant?->user;
        $firstName = $user?->first_name ?? '';
        $lastName = $user?->last_name ?? '';
        $assistantName = trim("{$firstName} {$lastName}") ?: 'Assistente';
        $initials = Str::upper(Str::substr((string) $firstName, 0, 1) . Str::substr((string) $lastName, 0, 1)) ?: 'AS';

        $documentUrl = null;
        if ($this->document_path) {
            $documentUrl = Storage::disk('local')->exists($this->document_path)
                ? url("/api/absences/{$this->id}/document")
                : null;
        }

        $startDate = $this->valid_from?->format('Y-m-d');
        $endDate = $this->valid_until?->format('Y-m-d') ?? $startDate;
        $startTime = $this->start_time ? substr($this->start_time, 0, 5) : null;
        $endTime = $this->end_time ? substr($this->end_time, 0, 5) : null;
        $reason = $this->absenceType?->name ?? '';
        $note = $this->notes ?? $this->justification ?? '';

        return [
            'id' => (string) $this->id,
            'assistant_id' => (string) $this->assistant_id,
            'assistant_name' => $assistantName,
            'assistant' => $assistantName,
            'initials' => $initials,
            'absence_type_id' => (string) $this->absence_type_id,
            'absence_type_name' => $reason,
            'reason' => $reason,
            'valid_from' => $startDate,
            'valid_until' => $endDate,
            'start' => $startDate,
            'end' => $endDate,
            'start_date' => $startDate,
            'end_date' => $endDate,
            'start_time' => $startTime,
            'end_time' => $endTime,
            'startTime' => $startTime,
            'endTime' => $endTime,
            'total_days' => (int) $this->total_days,
            'days' => (int) $this->total_days,
            'justification' => $this->justification,
            'notes' => $this->notes,
            'note' => $note,
            'status' => $this->status,
            'document_path' => $this->document_path,
            'documentPath' => $this->document_path,
            'document_url' => $documentUrl,
            'submitted' => $this->created_at?->format('d/m/Y') ?? '',
            'conflict' => false,
            'conflictDetail' => '',
            'created_by_user_id' => $this->created_by_user_id ? (string) $this->created_by_user_id : null,
            'justified_by_user_id' => $this->justified_by_user_id ? (string) $this->justified_by_user_id : null,
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}

