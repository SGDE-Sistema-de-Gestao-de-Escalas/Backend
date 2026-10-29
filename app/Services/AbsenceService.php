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
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

class AbsenceService
{
    /**
     * Create a new absence record.
     */
    public function create(array $data, ?UploadedFile $document = null, ?User $user = null): Absence
    {
        $absence = DB::transaction(function () use ($data, $document, $user) {
            $totalDays = $this->calculateTotalDays(
                $data['valid_from'] ?? null,
                $data['valid_until'] ?? null,
                $data['total_days'] ?? null
            );

            $documentName = null;
            $documentPath = null;
            if ($document) {
                $assistant = Assistant::with('user')->findOrFail($data['assistant_id']);
                $documentName = $document->getClientOriginalName();
                $documentPath = $this->storeAbsenceDocument($document, $assistant);
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

        $updated = DB::transaction(function () use ($absence, $data, $document, $user) {
            $validFrom = $data['valid_from'] ?? $absence->valid_from?->format('Y-m-d');
            $validUntil = array_key_exists('valid_until', $data) ? $data['valid_until'] : $absence->valid_until?->format('Y-m-d');

            $totalDays = $this->calculateTotalDays(
                $validFrom,
                $validUntil,
                $data['total_days'] ?? null
            );

            $documentName = $absence->document_name;
            $documentPath = $absence->document_path;

            // Remove existing document if requested
            if (! empty($data['remove_document'])) {
                $this->deleteAbsenceDocument($documentPath);
                $documentName = null;
                $documentPath = null;
            }

            // Replace with new document if provided (by Admin or Assistant)
            if ($document) {
                $this->deleteAbsenceDocument($documentPath);

                $targetAssistantId = $data['assistant_id'] ?? $absence->assistant_id;
                $assistant = Assistant::with('user')->findOrFail($targetAssistantId);

                $documentName = $document->getClientOriginalName();
                $documentPath = $this->storeAbsenceDocument($document, $assistant);
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

            return $absence->fresh()->load(['assistant.user', 'assistant.school', 'absenceType', 'createdByUser', 'justifiedByUser']);
        });

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

        $admins = User::query()
            ->where('is_active', true)
            ->whereNotNull('email')
            ->whereHas('role', fn ($q) => $q->where('slug', 'admin'))
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

        if ($absence->document_path) {
            $this->deleteAbsenceDocument($absence->document_path);
        }

        return (bool) $absence->delete();
    }

    /**
     * Obtém o disco configurado para documentos (ex: 'public' ou 'r2').
     */
    public function getDocumentDisk(): string
    {
        return config('filesystems.documents_disk');
    }

    /**
     * Gera o caminho da pasta para os comprovativos de faltas do assistente:
     * {nome_assistente}_{nr_interno}/absences_files
     *
     * Sanitiza carateres especiais (acentos, cedilhas, barras, símbolos)
     * para segurança em qualquer sistema de ficheiros (Windows/Linux) ou S3/R2.
     */
    public function getAssistantAbsenceDirectory(Assistant $assistant): string
    {
        $assistant->loadMissing('user');

        $firstName = $assistant->user?->first_name ?? 'assistente';
        $lastName = $assistant->user?->last_name ?? '';
        $internalNumber = $assistant->internal_number ?? (string) $assistant->id;

        $normalizedName = preg_replace('/[\/\\\_\-\.]+/', ' ', trim("{$firstName} {$lastName}"));
        $normalizedInternal = preg_replace('/[\/\\\_\-\.]+/', ' ', trim((string) $internalNumber));

        $safeName = Str::slug($normalizedName, '_') ?: 'assistente';
        $safeInternal = Str::slug($normalizedInternal, '_') ?: 'sem_numero';

        return "{$safeName}_{$safeInternal}/absences_files";
    }

    /**
     * Guarda o ficheiro comprovativo da falta no disco configurado (public ou r2) na pasta do assistente.
     * Gera um nome padronizado no disco: ausencia_{timestamp}_{random}.{ext}
     */
    public function storeAbsenceDocument(UploadedFile $file, Assistant $assistant): string
    {
        $folder = $this->getAssistantAbsenceDirectory($assistant);
        $extension = $file->getClientOriginalExtension() ?: $file->guessExtension() ?: 'pdf';
        $filename = 'ausencia_' . time() . '_' . Str::random(6) . ".{$extension}";

        $disk = $this->getDocumentDisk();

        return $file->storeAs($folder, $filename, $disk);
    }

    /**
     * Remove o ficheiro comprovativo da falta do disco configurado.
     */
    public function deleteAbsenceDocument(?string $path): void
    {
        if ($path) {
            $disk = $this->getDocumentDisk();

            try {
                Storage::disk($disk)->delete($path);
            } catch (\Throwable) {
            }
        }
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

