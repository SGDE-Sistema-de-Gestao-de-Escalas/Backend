<?php

namespace App\Mail;

use App\Models\Auth\User;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class SetupAccountMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public readonly User $user,
        public readonly string $token
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Bem-vindo(a) ao SGDE - Ative a sua conta',
        );
    }

    public function content(): Content
    {
        $this->user->loadMissing(['role', 'assistant.school']);
        $setupUrl = config('app.frontend_url') . '/setup-password?token=' . $this->token . '&email=' . urlencode($this->user->email);

        return new Content(
            markdown: 'emails.auth.setup-account',
            with: [
                'setupUrl' => $setupUrl,
                'roleName' => $this->user->role->name ?? 'Assistente Operacional',
                'schoolName' => $this->user->assistant?->school?->name,
                'internalNumber' => $this->user->assistant?->internal_number,
            ],
        );
    }
}