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
        $validFrom = $this->absence->valid_from
            ? Carbon::parse($this->absence->valid_from)->format('d/m/Y')
            : '';
        $validUntil = $this->absence->valid_until
            ? Carbon::parse($this->absence->valid_until)->format('d/m/Y')
            : $validFrom;

        $periodLabel = ($validFrom === $validUntil || $validUntil === '')
            ? $validFrom
            : "{$validFrom} a {$validUntil}";

        if ($this->absence->start_time && $this->absence->end_time) {
            $start = substr((string) $this->absence->start_time, 0, 5);
            $end = substr((string) $this->absence->end_time, 0, 5);
            $periodLabel .= " ({$start} às {$end})";
        }

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