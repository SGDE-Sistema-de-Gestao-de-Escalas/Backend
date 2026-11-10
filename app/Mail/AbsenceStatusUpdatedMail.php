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

class AbsenceStatusUpdatedMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public readonly Absence $absence,
        public readonly User $user,
        public readonly string $schoolName,
    ) {}

    public function envelope(): Envelope
    {
        $isJustified = $this->absence->status === 'justified';

        return new Envelope(
            subject: $isJustified
                ? 'SGDE - Falta Validada como Justificada'
                : 'SGDE - Falta Marcada como Injustificada',
        );
    }

    public function content(): Content
    {
        $periodLabel = $this->absence->formatPeriod();

        $isJustified = $this->absence->status === 'justified';
        $statusLabel = $isJustified ? 'Justificada' : 'Injustificada';

        $absencesUrl = rtrim((string) config('app.frontend_url'), '/') . '/staff/absences';

        return new Content(
            markdown: 'emails.absences.status-updated',
            with: [
                'user' => $this->user,
                'schoolName' => $this->schoolName,
                'absenceTypeName' => $this->absence->absenceType?->name ?? 'Ausência',
                'periodLabel' => $periodLabel,
                'statusLabel' => $statusLabel,
                'isJustified' => $isJustified,
                'notes' => $this->absence->notes,
                'absencesUrl' => $absencesUrl,
            ],
        );
    }
}