<?php

namespace App\Mail;

use App\Models\Auth\User;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class PrivacyDeactivationRequestMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public readonly User $user,
        public readonly string $schoolName,
        public readonly ?string $reason = null
    ) {}

    public function envelope(): Envelope
    {
        $userName = trim("{$this->user->first_name} {$this->user->last_name}");

        return new Envelope(
            subject: "SGDE - Pedido de Desativação de Conta (RGPD) por {$userName}",
        );
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'emails.privacy.deactivation-request',
            with: [
                'user' => $this->user,
                'schoolName' => $this->schoolName,
                'reason' => $this->reason,
            ],
        );
    }
}