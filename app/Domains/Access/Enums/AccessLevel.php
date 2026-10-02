<?php

namespace App\Domains\Access\Enums;

/**
 * Tingkat hak akses per modul pada sebuah role (F4-1).
 */
enum AccessLevel: string
{
    case View = 'view';
    case Manage = 'manage';

    public function label(): string
    {
        return match ($this) {
            self::View => 'Hanya lihat',
            self::Manage => 'Kelola penuh',
        };
    }

    /**
     * Level yang boleh dipilih untuk sebuah modul (modul baca-saja hanya
     * menawarkan "Hanya lihat").
     *
     * @return array<int, self>
     */
    public static function forModule(Module $module): array
    {
        return $module->supportsManage() ? [self::View, self::Manage] : [self::View];
    }
}
