<?php

namespace App\Domains\Services\Enums;

/**
 * Jenis reminder WhatsApp perpanjangan layanan (t_cc560a11).
 *
 * H-7/H-3/H-1 = layanan berakhir tepat N hari lagi (pengingat dini).
 * Overdue = layanan sudah lewat jatuh tempo (pengingat susulan, terkirim
 * sekali — bukan harian — karena baris `service_reminders` menjadi guard).
 */
enum ServiceReminderKind: string
{
    case HMinus7 = 'H-7';
    case HMinus3 = 'H-3';
    case HMinus1 = 'H-1';
    case Overdue = 'overdue';

    /** Sisa hari menuju berakhir; null untuk kind overdue. */
    public function daysBeforeEnd(): ?int
    {
        return match ($this) {
            self::HMinus7 => 7,
            self::HMinus3 => 3,
            self::HMinus1 => 1,
            self::Overdue => null,
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::Overdue => 'Lewat jatuh tempo',
            default => $this->value,
        };
    }

    /**
     * Kind yang cocok untuk sisa hari tertentu (positif = masih sebelum
     * berakhir; harus persis 7/3/1). Sisa hari ≤ 0 tidak lewat sini —
     * pemanggil menangani overdue sendiri.
     */
    public static function forDaysRemaining(int $daysRemaining): ?self
    {
        foreach (self::cases() as $kind) {
            if ($kind->daysBeforeEnd() === $daysRemaining) {
                return $kind;
            }
        }

        return null;
    }
}
