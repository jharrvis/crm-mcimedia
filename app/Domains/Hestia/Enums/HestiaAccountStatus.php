<?php

namespace App\Domains\Hestia\Enums;

/**
 * Status akun Hestia hasil sinkronisasi (F3-1).
 * Akun yang hilang dari Hestia atau di-suspend ditandai Inactive — tidak dihapus.
 */
enum HestiaAccountStatus: string
{
    case Active = 'active';
    case Inactive = 'inactive';

    public function label(): string
    {
        return match ($this) {
            self::Active => 'Aktif',
            self::Inactive => 'Nonaktif',
        };
    }
}
