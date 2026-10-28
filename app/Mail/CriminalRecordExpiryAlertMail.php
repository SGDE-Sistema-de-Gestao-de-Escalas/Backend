<?php

namespace App\Mail;

use App\Models\Assistants\Assistant;
use App\Models\Auth\User;
use Carbon\Carbon;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class CriminalRecordExpiryAlertMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public readonly Assistant $assistant,
        public readonly User $assistantUser,
        public readonly string $schoolName,
        public readonly int $daysRemaining,
    ) {}

    public function envelope(): Envelope
    {
        $subject = $this->daysRemaining <= 0
            ? 'SGDE - Alerta Crítico: Registo Criminal Expirado Hoje'
            : "SGDE - Aviso de Validade: Registo Criminal expira em {$this->daysRemaining} dias";

        return new Envelope(
            subject: $subject,
        );
    }

    public function content(): Content
    {
        $expiryFormatted = $this->assistant->criminal_record_expiry
            ? Carbon::parse($this->assistant->criminal_record_expiry)->format('d/m/Y')
            : '—';

        $assistantName = trim("{$this->assistantUser->first_name} {$this->assistantUser->last_name}");
        $internalNumber = $this->assistant->internal_number;
        $assistantLabel = $internalNumber
            ? "{$assistantName} ({$internalNumber})"
            : $assistantName;

        $statusLabel = $this->daysRemaining <= 0
            ? 'Expirado Hoje'
            : "Expira dentro de {$this->daysRemaining} dias";

        $assistantsAdminUrl = rtrim((string) config('app.frontend_url', config('app.url')), '/') . '/assistants';

        return new Content(
            markdown: 'emails.assistants.criminal-record-expiry',
            with: [
                'assistantLabel' => $assistantLabel,
                'assistantEmail' => $this->assistantUser->email,
                'schoolName' => $this->schoolName,
                'expiryFormatted' => $expiryFormatted,
                'daysRemaining' => $this->daysRemaining,
                'statusLabel' => $statusLabel,
                'isUrgent' => $this->daysRemaining <= 7,
                'assistantsAdminUrl' => $assistantsAdminUrl,
            ],
        );
    }
}

