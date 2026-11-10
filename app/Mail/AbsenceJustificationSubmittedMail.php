<?php

namespace App\Mail;

use App\Models\Absences\Absence;
use App\Models\Auth\User;
use Carbon\Carbon;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class AbsenceJustificationSubmittedMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public readonly Absence $absence,
        public readonly User $assistantUser,
        public readonly string $schoolName,
    ) {}

    public function envelope(): Envelope
    {
        $assistantName = trim("{$this->assistantUser->first_name} {$this->assistantUser->last_name}");

        return new Envelope(
            subject: "SGDE - Justificação de Falta Submetida por {$assistantName}",
        );
    }

    public function content(): Content
    {
        $periodLabel = $this->absence->formatPeriod();

        $internalNumber = $this->absence->assistant?->internal_number;
        $assistantName = trim("{$this->assistantUser->first_name} {$this->assistantUser->last_name}");
        $assistantLabel = $internalNumber
            ? "{$assistantName} ({$internalNumber})"
            : $assistantName;

        $absencesAdminUrl = rtrim((string) config('app.frontend_url'), '/') . '/absences';

        return new Content(
            markdown: 'emails.absences.justification-submitted',
            with: [
                'assistantLabel' => $assistantLabel,
                'schoolName' => $this->schoolName,
                'absenceTypeName' => $this->absence->absenceType?->name ?? 'Ausência',
                'periodLabel' => $periodLabel,
                'documentName' => $this->absence->document_name,
                'justification' => $this->absence->justification,
                'absencesAdminUrl' => $absencesAdminUrl,
            ],
        );
    }
}

