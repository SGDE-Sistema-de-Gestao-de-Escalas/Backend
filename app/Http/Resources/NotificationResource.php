<?php

namespace App\Http\Resources;

use App\Enums\NotificationType;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin \App\Models\System\Notification
 */
class NotificationResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        // Notificações antigas/anonimizadas (type "anonymized") aparecem como "info".
        $type = NotificationType::tryFrom((string) $this->type)?->value ?? NotificationType::Info->value;

        return [
            'id' => $this->id,
            'type' => $type,
            'event' => $this->event,
            'title' => $this->title,
            'body' => $this->body,
            'data' => $this->data,
            'link' => $this->data['link'] ?? null,
            'school_id' => $this->school_id,
            'read' => $this->read_at !== null,
            'read_at' => $this->read_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
