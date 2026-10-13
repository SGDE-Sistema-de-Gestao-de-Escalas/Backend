<?php

namespace App\Services;

use App\Models\Absences\Absence;
use App\Models\Auth\User;
use Carbon\Carbon;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class AbsenceService
{
    /**
     * Create a new absence record.
     */
    public function create(array $data, ?UploadedFile $document = null, ?User $user = null): Absence
    {
        return DB::transaction(function () use ($data, $document, $user) {
            $totalDays = $this->calculateTotalDays(
                $data['valid_from'] ?? null,
                $data['valid_until'] ?? null,
                $data['total_days'] ?? null
            );

            $documentPath = null;
            if ($document) {
                $documentPath = Storage::disk('local')->putFile('absences', $document);
            }

            $status = $data['status'] ?? 'pending';
            $justifiedByUserId = in_array($status, ['justified', 'unjustified'], true) ? $user?->id : null;

            $absence = Absence::create([
                'assistant_id' => $data['assistant_id'],
                'absence_type_id' => $data['absence_type_id'],
                'created_by_user_id' => $user?->id,
                'justified_by_user_id' => $justifiedByUserId,
                'valid_from' => $data['valid_from'],
                'valid_until' => $data['valid_until'] ?? $data['valid_from'],
                'start_time' => $data['start_time'] ?? null,
                'end_time' => $data['end_time'] ?? null,
                'total_days' => $totalDays,
                'justification' => $data['justification'] ?? null,
                'notes' => $data['notes'] ?? null,
                'status' => $status,
                'document_path' => $documentPath,
            ]);

            return $absence->load(['assistant.user', 'absenceType', 'createdByUser', 'justifiedByUser']);
        });
    }

    /**
     * Update an existing absence record.
     */
    public function update(Absence $absence, array $data, ?UploadedFile $document = null, ?User $user = null): Absence
    {
        return DB::transaction(function () use ($absence, $data, $document, $user) {
            $validFrom = $data['valid_from'] ?? $absence->valid_from?->format('Y-m-d');
            $validUntil = array_key_exists('valid_until', $data) ? $data['valid_until'] : $absence->valid_until?->format('Y-m-d');

            $totalDays = $this->calculateTotalDays(
                $validFrom,
                $validUntil,
                $data['total_days'] ?? null
            );

            $documentPath = $absence->document_path;

            // Remove existing document if requested
            if (! empty($data['remove_document'])) {
                if ($documentPath && Storage::disk('local')->exists($documentPath)) {
                    Storage::disk('local')->delete($documentPath);
                }
                $documentPath = null;
            }

            // Replace with new document if provided
            if ($document) {
                if ($documentPath && Storage::disk('local')->exists($documentPath)) {
                    Storage::disk('local')->delete($documentPath);
                }
                $documentPath = Storage::disk('local')->putFile('absences', $document);
            }

            $updatePayload = [
                'total_days' => $totalDays,
                'document_path' => $documentPath,
            ];

            if (isset($data['assistant_id'])) {
                $updatePayload['assistant_id'] = $data['assistant_id'];
            }
            if (isset($data['absence_type_id'])) {
                $updatePayload['absence_type_id'] = $data['absence_type_id'];
            }
            if (isset($data['valid_from'])) {
                $updatePayload['valid_from'] = $data['valid_from'];
            }
            if (array_key_exists('valid_until', $data)) {
                $updatePayload['valid_until'] = $data['valid_until'] ?? ($updatePayload['valid_from'] ?? $validFrom);
            }
            if (array_key_exists('start_time', $data)) {
                $updatePayload['start_time'] = $data['start_time'];
            }
            if (array_key_exists('end_time', $data)) {
                $updatePayload['end_time'] = $data['end_time'];
            }
            if (array_key_exists('justification', $data)) {
                $updatePayload['justification'] = $data['justification'];
            }
            if (array_key_exists('notes', $data)) {
                $updatePayload['notes'] = $data['notes'];
            }

            if (isset($data['status'])) {
                $updatePayload['status'] = $data['status'];
                if (in_array($data['status'], ['justified', 'unjustified'], true)) {
                    $updatePayload['justified_by_user_id'] = $user?->id;
                } else {
                    $updatePayload['justified_by_user_id'] = null;
                }
            }

            $absence->update($updatePayload);

            return $absence->fresh()->load(['assistant.user', 'absenceType', 'createdByUser', 'justifiedByUser']);
        });
    }

    /**
     * Delete an absence record.
     */
    public function delete(Absence $absence): bool
    {
        return (bool) $absence->delete();
    }

    /**
     * Calculate total days between dates.
     */
    private function calculateTotalDays(?string $validFrom, ?string $validUntil, ?int $totalDays = null): int
    {
        if ($totalDays !== null && $totalDays > 0) {
            return $totalDays;
        }

        if (! $validFrom) {
            return 1;
        }

        $from = Carbon::parse($validFrom)->startOfDay();
        $until = $validUntil ? Carbon::parse($validUntil)->startOfDay() : $from;

        return max(1, (int) $from->diffInDays($until) + 1);
    }
}

