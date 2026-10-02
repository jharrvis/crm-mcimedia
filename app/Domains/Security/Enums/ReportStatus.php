<?php

namespace App\Domains\Security\Enums;

/**
 * Status laporan keamanan bulanan (F3-3).
 *
 * Hanya laporan berstatus `sent` yang terlihat oleh klien melalui tautan
 * publik (magic link) — `draft` tetap internal.
 */
enum ReportStatus: string
{
    case Draft = 'draft';
    case Sent = 'sent';

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Draf',
            self::Sent => 'Terkirim',
        };
    }

    public function badgeClass(): string
    {
        return match ($this) {
            self::Draft => 'bg-amber-100 text-amber-700 dark:bg-amber-900 dark:text-amber-200',
            self::Sent => 'bg-green-100 text-green-700 dark:bg-green-900 dark:text-green-200',
        };
    }
}
