<?php

namespace App\Services;

use App\Mail\AbsenceJustificationSubmittedMail;
use App\Mail\AbsenceRegisteredMail;
use App\Mail\AbsenceStatusUpdatedMail;
use App\Models\Absences\Absence;
use App\Models\Assistants\Assistant;
use App\Models\Auth\User;
use Carbon\Carbon;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\ValidationException;
use Throwable;

class AbsenceService
{
    public function __construct(
        private readonly AssistantDocumentStorageService $documentStorage
    ) {}

    /**
     * Create a new absence record.
     */
    public function create(array $data, ?UploadedFile $document = null, ?User $user = null): Absence
    {
        $storedDocumentPath = null;

        try {
            $absence = DB::transaction(function () use ($data, $document, $user, &$storedDocumentPath) {
                $totalDays = $this->calculateTotalDays(
                    $data['valid_from'] ?? null,
                    $data['valid_until'] ?? null,
                    $data['total_days'] ?? null
                );

                $this->assertNoOverlap(
                    $data['assistant_id'],
                    $data['valid_from'],
                    $data['valid_until'] ?? $data['valid_from'],
                    $data['start_time'] ?? null,
                    $data['end_time'] ?? null,
                );

                $documentName = null;
                $documentPath = null;
                if ($document) {
                    $assistant = Assistant::with('user')->findOrFail($data['assistant_id']);
                    $documentName = $document->getClientOriginalName();
                    $documentPath = $this->documentStorage->storeAbsenceDocument($document, $assistant);
                    $storedDocumentPath = $documentPath;
                }

                // Só o administrador decide o estado de uma falta. Um assistente cria-a
                // sempre como pendente, mesmo que envie outro valor (o frontend envia "pending").
                $status = ($user !== null && ! $user->isAdmin())
                    ? 'pending'
                    : ($data['status'] ?? 'pending');
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
                    'document_name' => $documentName,
                    'document_path' => $documentPath,
                ]);

                return $absence->load(['assistant.user', 'assistant.school', 'absenceType', 'createdByUser', 'justifiedByUser']);
            });
        } catch (Throwable $e) {
            $this->documentStorage->deleteFile($storedDocumentPath);
            throw $e;
        }

        // Enviar email ao assistente quando o administrador regista uma falta em seu nome
        if ($user && $user->isAdmin()) {
            $this->sendAbsenceRegisteredEmail($absence);
        } elseif (
            $user &&
            ! $user->isAdmin() &&
            ($document !== null || trim((string) ($absence->justification ?? '')) !== '')
        ) {
            $this->sendAbsenceJustificationSubmittedEmail($absence);
        }

        return $absence;
    }

    /**
     * Update an existing absence record.
     */
    public function update(Absence $absence, array $data, ?UploadedFile $document = null, ?User $user = null): Absence
    {
        $previousStatus = (string) $absence->status;
        $previousJustification = trim((string) ($absence->justification ?? ''));

        $storedDocumentPath = null;

        try {
            $updated = DB::transaction(function () use ($absence, $data, $document, $user, &$storedDocumentPath) {
                $validFrom = $data['valid_from'] ?? $absence->valid_from?->format('Y-m-d');
                $validUntil = array_key_exists('valid_until', $data) ? $data['valid_until'] : $absence->valid_until?->format('Y-m-d');

                $totalDays = $this->calculateTotalDays(
                    $validFrom,
                    $validUntil,
                    $data['total_days'] ?? null
                );

                $assistantId = $data['assistant_id'] ?? $absence->assistant_id;
                $newUntil = array_key_exists('valid_until', $data)
                    ? ($data['valid_until'] ?? $validFrom)
                    : $absence->valid_until?->format('Y-m-d');
                $newStart = array_key_exists('start_time', $data) ? $data['start_time'] : $absence->start_time;
                $newEnd = array_key_exists('end_time', $data) ? $data['end_time'] : $absence->end_time;

                $scheduleChanged = (string) $assistantId !== (string) $absence->assistant_id
                    || $this->dateOnly($validFrom) !== $absence->valid_from?->format('Y-m-d')
                    || $this->dateOnly($newUntil) !== $absence->valid_until?->format('Y-m-d')
                    || $this->timeOnly($newStart) !== $this->timeOnly($absence->start_time)
                    || $this->timeOnly($newEnd) !== $this->timeOnly($absence->end_time);

                if ($scheduleChanged) {
                    $this->assertNoOverlap($assistantId, $validFrom, $newUntil, $newStart, $newEnd, $absence->id);
                }

                $documentName = $absence->document_name;
                $documentPath = $absence->document_path;
                $obsoleteDocumentPath = null;

                // Remove existing document if requested
                if (! empty($data['remove_document'])) {
                    $obsoleteDocumentPath = $documentPath;
                    $documentName = null;
                    $documentPath = null;
                }

                // Replace with new document if provided (by Admin or Assistant)
                if ($document) {
                    $obsoleteDocumentPath ??= $documentPath;

                    $targetAssistantId = $data['assistant_id'] ?? $absence->assistant_id;
                    $assistant = Assistant::with('user')->findOrFail($targetAssistantId);

                    $documentName = $document->getClientOriginalName();
                    $documentPath = $this->documentStorage->storeAbsenceDocument($document, $assistant);
                    $storedDocumentPath = $documentPath;
                }

                $updatePayload = [
                    'total_days' => $totalDays,
                    'document_name' => $documentName,
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

                if ($obsoleteDocumentPath !== null) {
                    DB::afterCommit(fn () => $this->documentStorage->deleteFile($obsoleteDocumentPath));
                }

                return $absence->fresh()->load(['assistant.user', 'assistant.school', 'absenceType', 'createdByUser', 'justifiedByUser']);
            });
        } catch (Throwable $e) {
            $this->documentStorage->deleteFile($storedDocumentPath);
            throw $e;
        }

        // Enviar email ao assistente quando o administrador avalia a falta como Justificada ou Injustificada
        if (
            $user &&
            $user->isAdmin() &&
            $updated->status !== $previousStatus &&
            in_array($updated->status, ['justified', 'unjustified'], true)
        ) {
            $this->sendAbsenceStatusUpdatedEmail($updated);
        } elseif ($user && ! $user->isAdmin()) {
            $currentJustification = trim((string) ($updated->justification ?? ''));
            $hasNewJustification = $currentJustification !== '' && $currentJustification !== $previousJustification;

            if ($document !== null || $hasNewJustification) {
                $this->sendAbsenceJustificationSubmittedEmail($updated);
            }
        }

        return $updated;
    }

    /**
     * Envia notificação por email aos administradores ativos quando um assistente submete
     * uma justificação ou comprovativo de falta.
     */
    private function sendAbsenceJustificationSubmittedEmail(Absence $absence): void
    {
        $assistant = $absence->assistant;
        $assistantUser = $assistant?->user;

        if (! $assistant || $assistant->is_anonymized || ! $assistantUser) {
            return;
        }

        $admins = User::activeAdmins()
            ->whereNotNull('email')
            ->get();

        if ($admins->isEmpty()) {
            return;
        }

        $schoolName = $assistant->school?->name ?? 'Estabelecimento de Ensino';

        try {
            Mail::to($admins)->send(new AbsenceJustificationSubmittedMail(
                absence: $absence,
                assistantUser: $assistantUser,
                schoolName: $schoolName,
            ));
        } catch (Throwable $e) {
            report($e);
        }
    }

    /**
     * Envia notificação por email ao assistente quando uma falta é registada pela administração.
     */
    private function sendAbsenceRegisteredEmail(Absence $absence): void
    {
        $assistant = $absence->assistant;
        $recipient = $assistant?->user;

        if (! $assistant || $assistant->is_anonymized || ! $recipient || ! $recipient->is_active || ! $recipient->email) {
            return;
        }

        $schoolName = $assistant->school?->name ?? 'Estabelecimento de Ensino';

        try {
            Mail::to($recipient->email)->send(new AbsenceRegisteredMail(
                absence: $absence,
                user: $recipient,
                schoolName: $schoolName,
            ));
        } catch (Throwable $e) {
            report($e);
        }
    }

    /**
     * Envia notificação por email ao assistente quando o estado da falta passa a Justificada ou Injustificada.
     */
    private function sendAbsenceStatusUpdatedEmail(Absence $absence): void
    {
        $assistant = $absence->assistant;
        $recipient = $assistant?->user;

        if (! $assistant || $assistant->is_anonymized || ! $recipient || ! $recipient->is_active || ! $recipient->email) {
            return;
        }

        $schoolName = $assistant->school?->name ?? 'Estabelecimento de Ensino';

        try {
            Mail::to($recipient->email)->send(new AbsenceStatusUpdatedMail(
                absence: $absence,
                user: $recipient,
                schoolName: $schoolName,
            ));
        } catch (Throwable $e) {
            report($e);
        }
    }

    /**
     * Delete an absence record.
     */
    public function delete(Absence $absence): bool
    {
        $today = Carbon::today();
        $validFrom = $absence->valid_from ? Carbon::parse($absence->valid_from)->startOfDay() : null;
        $validUntil = $absence->valid_until ? Carbon::parse($absence->valid_until)->startOfDay() : $validFrom;
        $isPast = $validUntil && $validUntil->isBefore($today);

        if ($isPast && $absence->status !== 'pending') {
            throw ValidationException::withMessages([
                'absence' => 'Não é possível eliminar uma falta passada já registada no histórico.',
            ]);
        }

        $documentPath = $absence->document_path;

        return DB::transaction(function () use ($absence, $documentPath) {
            $deleted = (bool) $absence->delete();

            if ($deleted && $documentPath) {
                DB::afterCommit(fn () => $this->documentStorage->deleteFile($documentPath));
            }

            return $deleted;
        });
    }

    /**
     * Recusa a falta se o assistente já tiver outra (não eliminada) que se sobreponha em datas e horas.
     * Uma data de fim vazia na falta existente significa sem fim (bloqueia tudo o que vem depois).
     */
    private function assertNoOverlap(
        string $assistantId,
        string $from,
        ?string $until,
        ?string $startTime,
        ?string $endTime,
        ?string $ignoreAbsenceId = null,
    ): void {
        Assistant::query()->whereKey($assistantId)->lockForUpdate()->first();

        $query = Absence::query()
            ->where('assistant_id', $assistantId)
            ->whereDate('valid_from', '<=', $this->dateOnly($until) ?? '2100-12-31')
            ->where(function ($q) use ($from) {
                $q->whereNull('valid_until')->orWhereDate('valid_until', '>=', $this->dateOnly($from));
            });

        if ($ignoreAbsenceId !== null) {
            $query->where('id', '!=', $ignoreAbsenceId);
        }

        $newFrom = $this->minuteOfDay($startTime, 0);
        $newTo = $this->minuteOfDay($endTime, 24 * 60);

        foreach ($query->get(['id', 'start_time', 'end_time']) as $existing) {
            $existingFrom = $this->minuteOfDay($existing->start_time, 0);
            $existingTo = $this->minuteOfDay($existing->end_time, 24 * 60);

            if ($newFrom < $existingTo && $existingFrom < $newTo) {
                throw ValidationException::withMessages([
                    'valid_from' => 'Este assistente já tem uma falta registada que se sobrepõe a este período.',
                ]);
            }
        }
    }

    private function dateOnly(?string $value): ?string
    {
        return $value ? substr($value, 0, 10) : null;
    }

    private function timeOnly(?string $value): ?string
    {
        return $value ? substr($value, 0, 5) : null;
    }

    private function minuteOfDay(?string $time, int $default): int
    {
        $time = $this->timeOnly($time);

        if ($time === null) {
            return $default;
        }

        return \App\Support\TimeHelper::toMinutes($time);
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

