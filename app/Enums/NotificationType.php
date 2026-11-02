<?php

namespace App\Enums;

/**
 * Categoria visível no sino de notificações (tem de coincidir com `NotificationType` do Frontend).
 */
enum NotificationType: string
{
    case Alert = 'alert';       // Alertas: precisam de ação do administrador
    case Absence = 'absence';   // Faltas
    case Expiry = 'expiry';     // Vigências a expirar
    case Info = 'info';         // Informação / resultado de operações

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(fn (self $t) => $t->value, self::cases());
    }
}
