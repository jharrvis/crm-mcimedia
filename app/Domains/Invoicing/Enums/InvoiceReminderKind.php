<?php

namespace App\Domains\Invoicing\Enums;

/**
 * Jenis pengingat invoice jatuh tempo (F2-6) berdasarkan umur keterlambatan.
 */
enum InvoiceReminderKind: string
{
    case H1 = 'H+1';
    case H7 = 'H+7';
    case H14 = 'H+14';

    /** Umur keterlambatan (hari) yang memicu pengingat ini. */
    public function days(): int
    {
        return match ($this) {
            self::H1 => 1,
            self::H7 => 7,
            self::H14 => 14,
        };
    }

    public function label(): string
    {
        return "H+{$this->days()}";
    }

    /** Kind yang cocok untuk umur keterlambatan tertentu, atau null bila tidak ada. */
    public static function forDaysOverdue(int $days): ?self
    {
        foreach (self::cases() as $kind) {
            if ($kind->days() === $days) {
                return $kind;
            }
        }

        return null;
    }
}
