<?php

namespace App\Domains\Security\Enums;

/**
 * Status tindak lanjut insiden keamanan (F3-3).
 */
enum IncidentStatus: string
{
    case Open = 'open';
    case Mitigated = 'mitigated';
    case Resolved = 'resolved';

    public function label(): string
    {
        return match ($this) {
            self::Open => 'Terbuka',
            self::Mitigated => 'Dimitigasi',
            self::Resolved => 'Selesai',
        };
    }

    public function badgeClass(): string
    {
        return match ($this) {
            self::Open => 'bg-red-100 text-red-700 dark:bg-red-900 dark:text-red-200',
            self::Mitigated => 'bg-amber-100 text-amber-700 dark:bg-amber-900 dark:text-amber-200',
            self::Resolved => 'bg-green-100 text-green-700 dark:bg-green-900 dark:text-green-200',
        };
    }

    public function isOpen(): bool
    {
        return $this === self::Open;
    }
}
