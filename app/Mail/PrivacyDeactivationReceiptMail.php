<?php

namespace App\Mail;

use App\Models\Auth\User;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class PrivacyDeactivationReceiptMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public readonly User $user,
        public readonly string $schoolName,
        public readonly ?string $reason = null
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'SGDE - Comprovativo de Pedido de Anonimização de Dados (RGPD)',
        );
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'emails.privacy.deactivation-receipt',
            with: [
                'user' => $this->user,
                'schoolName' => $this->schoolName,
                'reason' => $this->reason,
            ],
        );
    }
}
