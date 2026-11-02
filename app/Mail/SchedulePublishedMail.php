<?php

namespace App\Mail;

use App\Models\Auth\User;
use Carbon\Carbon;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class SchedulePublishedMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public readonly User $user,
        public readonly string $schoolName,
        public readonly string $startDate,
        public readonly string $endDate,
        public readonly bool $isUpdate = false,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: $this->isUpdate
                ? 'SGDE - Atualização de Escala de Serviço'
                : 'SGDE - Nova Escala de Serviço Publicada',
        );
    }

    public function content(): Content
    {
        $formattedStart = Carbon::parse($this->startDate)->format('d/m/Y');
        $formattedEnd = Carbon::parse($this->endDate)->format('d/m/Y');

        $periodLabel = $this->startDate === $this->endDate
            ? $formattedStart
            : "{$formattedStart} a {$formattedEnd}";

        $scheduleUrl = rtrim((string) config('app.frontend_url', config('app.url')), '/') . '/staff/schedule';

        return new Content(
            markdown: 'emails.schedules.published',
            with: [
                'user' => $this->user,
                'schoolName' => $this->schoolName,
                'periodLabel' => $periodLabel,
                'isSingleDay' => $this->startDate === $this->endDate,
                'isUpdate' => $this->isUpdate,
                'scheduleUrl' => $scheduleUrl,
            ],
        );
    }
}