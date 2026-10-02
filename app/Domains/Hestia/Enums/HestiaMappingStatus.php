<?php

namespace App\Domains\Hestia\Enums;

/**
 * Status pemetaan akun Hestia ke klien CRM (F3-1).
 */
enum HestiaMappingStatus: string
{
    case Auto = 'auto';         // dicocokkan otomatis oleh sistem
    case Mapped = 'mapped';     // dipetakan manual oleh admin
    case Unmapped = 'unmapped'; // belum cocok — menunggu pemetaan manual
    case Ignored = 'ignored';   // sengaja diabaikan admin

    public function label(): string
    {
        return match ($this) {
            self::Auto => 'Otomatis',
            self::Mapped => 'Manual',
            self::Unmapped => 'Belum dipetakan',
            self::Ignored => 'Diabaikan',
        };
    }

    public function isResolved(): bool
    {
        return $this === self::Auto || $this === self::Mapped;
    }
}
