<?php

namespace App\Domains\Hestia\Enums;

/**
 * Level alert kuota disk untuk satu akun Hestia (t_afef420a).
 *
 * `none`     = pemakaian di bawah ambang warning (atau belum ada data).
 * `warning`  = pemakaian >= ambang warning (default 80%) dan < ambang critical.
 * `critical` = pemakaian >= ambang critical (default 90%).
 *
 * Nilai disimpan di `hestia_accounts.disk_alert_level` sebagai state mesin
 * idempoten: notifikasi & insiden hanya dibuat saat level BERUBAH.
 */
enum DiskAlertLevel: string
{
    case None = 'none';
    case Warning = 'warning';
    case Critical = 'critical';

    public function label(): string
    {
        return match ($this) {
            self::None => 'Normal',
            self::Warning => 'Warning',
            self::Critical => 'Kritis',
        };
    }

    /** Kelas Tailwind untuk badge (light + dark), konsisten dengan badge status lain. */
    public function badgeClass(): string
    {
        return match ($this) {
            self::None => 'bg-green-100 text-green-700 dark:bg-green-900 dark:text-green-200',
            self::Warning => 'bg-amber-100 text-amber-800 dark:bg-amber-900 dark:text-amber-200',
            self::Critical => 'bg-red-100 text-red-700 dark:bg-red-900 dark:text-red-200',
        };
    }
}