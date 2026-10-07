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
            subject: 'Bem-vindo ao SGDE - Configuração de Conta',
        );
    }

    public function content(): Content
    {
        // Link apontando para a tua SPA em React
        $setupUrl = config('app.frontend_url') . '/setup-password?token=' . $this->token . '&email=' . urlencode($this->user->email);

        return new Content(
            markdown: 'emails.auth.setup-account',
            with: [
                'setupUrl' => $setupUrl,
                'roleName' => $this->user->role->name ?? 'Utilizador',
            ],
        );
    }
}