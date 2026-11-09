<?php

namespace App\Support;

class TimeHelper
{
    /**
     * Converte uma string de hora ("HH:MM" ou "HH:MM:SS") para minutos desde a meia-noite.
     */
    public static function toMinutes(?string $time): int
    {
        if ($time === null || trim($time) === '') {
            return 0;
        }

        $parts = explode(':', trim($time));
        $hours = (int) ($parts[0] ?? 0);
        $minutes = (int) ($parts[1] ?? 0);

        return ($hours * 60) + $minutes;
    }

    /**
     * Converte minutos desde a meia-noite para o formato "HH:MM".
     */
    public static function toTimeString(int $minutes): string
    {
        $clamped = max(0, $minutes);
        $hours = intdiv($clamped, 60);
        $mins = $clamped % 60;

        return sprintf('%02d:%02d', $hours, $mins);
    }
}
