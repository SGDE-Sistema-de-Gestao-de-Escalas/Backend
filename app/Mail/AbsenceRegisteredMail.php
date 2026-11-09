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

class AbsenceRegisteredMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public readonly Absence $absence,
        public readonly User $user,
        public readonly string $schoolName,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'SGDE - Registo de Falta / Ausência',
        );
    }

    public function content(): Content
    {
        $periodLabel = $this->absence->formatPeriod();

        $statusLabels = [
            'pending' => 'Pendente de Justificação',
            'justified' => 'Justificada',
            'unjustified' => 'Injustificada',
        ];
        $statusLabel = $statusLabels[$this->absence->status] ?? 'Pendente de Justificação';

        $requiresDocument = (bool) ($this->absence->absenceType?->requires_document ?? false)
            && empty($this->absence->document_path);

        $absencesUrl = rtrim((string) config('app.frontend_url', config('app.url')), '/') . '/staff/absences';

        return new Content(
            markdown: 'emails.absences.registered',
            with: [
                'user' => $this->user,
                'schoolName' => $this->schoolName,
                'absenceTypeName' => $this->absence->absenceType?->name ?? 'Ausência',
                'periodLabel' => $periodLabel,
                'statusLabel' => $statusLabel,
                'status' => $this->absence->status,
                'requiresDocument' => $requiresDocument,
                'notes' => $this->absence->notes,
                'absencesUrl' => $absencesUrl,
            ],
        );
    }
}