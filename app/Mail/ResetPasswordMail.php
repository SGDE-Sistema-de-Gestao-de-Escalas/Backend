<?php

namespace App\Mail;

use App\Models\Auth\User;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class ResetPasswordMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public readonly User $user,
        public readonly string $token
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'SGDE - Recuperação de Palavra-passe',
        );
    }

    public function content(): Content
    {
        $resetUrl = config('app.frontend_url') . '/reset-password?token=' . $this->token . '&email=' . urlencode($this->user->email);

        return new Content(
            markdown: 'emails.auth.reset-password',
            with: [
                'resetUrl' => $resetUrl,
            ],
        );
    }
}