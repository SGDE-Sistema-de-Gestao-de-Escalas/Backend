<?php

namespace App\Http\Resources;

use Carbon\Carbon;
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

        $documentUrl = $this->document_path
            ? $this->getDocumentUrl($this->document_path)
            : null;

        $startDate = $this->valid_from?->format('Y-m-d');
        $endDate = $this->valid_until?->format('Y-m-d') ?? $startDate;
        $startTime = $this->start_time ? substr($this->start_time, 0, 5) : null;
        $endTime = $this->end_time ? substr($this->end_time, 0, 5) : null;
        $reason = $this->absenceType?->name ?? '';
        $note = $this->notes ?? $this->justification ?? '';

        $requiresDocument = (bool) ($this->absenceType?->requires_document ?? false);
        $hasDocument = ! empty($this->document_path);
        $missingDocument = $requiresDocument && ! $hasDocument;
        $warning = $missingDocument
            ? 'Este tipo de ausência requer a apresentação de documento comprovativo.'
            : null;

        $requestUser = $request->user();
        $isAdmin = $requestUser ? $requestUser->isAdmin() : false;
        $isOwnAbsence = $requestUser && $assistant && $assistant->user_id === $requestUser->id;

        $today = Carbon::today();
        $validFromDate = $this->valid_from ? Carbon::parse($this->valid_from)->startOfDay() : null;
        $validUntilDate = $this->valid_until ? Carbon::parse($this->valid_until)->startOfDay() : $validFromDate;
        $isPast = $validUntilDate && $validUntilDate->isBefore($today);
        $isPending = $this->status === 'pending';

        if ($isAdmin) {
            // Admin pode editar e eliminar totalmente se estiver Pendente (qualquer data) ou se for atual/futura
            if ($isPending || ! $isPast) {
                $canEdit = true;
                $canDelete = true;
                $cannotEditReason = null;
                $cannotDeleteReason = null;
            } else {
                // Falta já Justificada/Injustificada no passado
                $canEdit = false;
                $canDelete = false;
                $cannotEditReason = 'Esta falta já foi validada e encerrada pela direção.';
                $cannotDeleteReason = 'Não é possível eliminar uma falta passada já registada no histórico.';
            }
            $canJustify = true;
            $canAttachDocument = true;
        } else {
            // Staff (Assistente): nunca elimina nem edita dados base; apenas anexa documento/notas enquanto está Pendente
            $canEdit = false;
            $canDelete = false;
            $canJustify = false;

            if (! $isOwnAbsence) {
                $canAttachDocument = false;
                $cannotEditReason = 'Não tem permissão para editar faltas de outros colaboradores.';
                $cannotDeleteReason = 'Não tem permissão para eliminar faltas de outros colaboradores.';
            } elseif ($isPending) {
                $canAttachDocument = true;
                $cannotEditReason = 'O assistente apenas pode anexar documento comprovativo ou editar notas.';
                $cannotDeleteReason = 'Apenas os administradores podem eliminar ausências.';
            } elseif ($this->status === 'justified') {
                $canAttachDocument = false;
                $cannotEditReason = 'A falta já se encontra aprovada. Contacte a direção para alterações.';
                $cannotDeleteReason = 'A falta já se encontra aprovada. Contacte a direção para alterações.';
            } else {
                $canAttachDocument = false;
                $cannotEditReason = 'Faltas injustificadas não podem ser alteradas ou eliminadas.';
                $cannotDeleteReason = 'Faltas injustificadas não podem ser alteradas ou eliminadas.';
            }
        }

        return [
            'id' => (string) $this->id,
            'assistant_id' => (string) $this->assistant_id,
            'assistant_name' => $assistantName,
            'assistant' => $assistantName,
            'initials' => $initials,
            'absence_type_id' => (string) $this->absence_type_id,
            'absence_type_name' => $reason,
            'reason' => $reason,
            'requires_document' => $requiresDocument,
            'requiresDocument' => $requiresDocument,
            'has_document' => $hasDocument,
            'hasDocument' => $hasDocument,
            'missing_document' => $missingDocument,
            'missingDocument' => $missingDocument,
            'warning' => $warning,
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
            'document_name' => $this->document_name,
            'documentName' => $this->document_name,
            'document_path' => $this->document_path,
            'documentPath' => $this->document_path,
            'document_url' => $documentUrl,
            'documentUrl' => $documentUrl,
            'submitted' => $this->created_at?->format('d/m/Y') ?? '',
            'conflict' => false,
            'conflictDetail' => '',
            'can_edit' => $canEdit,
            'canEdit' => $canEdit,
            'can_delete' => $canDelete,
            'canDelete' => $canDelete,
            'can_justify' => $canJustify,
            'canJustify' => $canJustify,
            'can_attach_document' => $canAttachDocument,
            'canAttachDocument' => $canAttachDocument,
            'cannot_edit_reason' => $cannotEditReason,
            'cannot_delete_reason' => $cannotDeleteReason,
            'created_by_user_id' => $this->created_by_user_id ? (string) $this->created_by_user_id : null,
            'justified_by_user_id' => $this->justified_by_user_id ? (string) $this->justified_by_user_id : null,
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }

    private function getDocumentUrl(string $path): string
    {
        $disk = config('filesystems.documents_disk');

        try {
            return Storage::disk($disk)->url($path);
        } catch (\Throwable) {
            return route('absences.document', ['absence' => $this->id]);
        }
    }
}

