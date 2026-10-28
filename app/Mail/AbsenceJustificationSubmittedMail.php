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
        return new Envelope(
            subject: 'SGDE - Novo Comprovativo / Justificação de Falta Submetido',
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

        $internalNumber = $this->absence->assistant?->internal_number;
        $assistantName = trim("{$this->assistantUser->first_name} {$this->assistantUser->last_name}");
        $assistantLabel = $internalNumber
            ? "{$assistantName} ({$internalNumber})"
            : $assistantName;

        $absencesAdminUrl = rtrim((string) config('app.frontend_url', config('app.url')), '/') . '/absences';

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

