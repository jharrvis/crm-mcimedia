<?php

namespace App\Domains\Security\Enums;

/**
 * Siklus hidup target scan WPScan (t_2e555b0b).
 *
 * pending_detection : baru dibuat dari akun Hestia, belum diverifikasi WordPress.
 * active            : terdeteksi WordPress, ikut dipindai setiap jadwal.
 * non_wordpress     : probe tidak menemukan tanda WordPress — dilewati permanen
 *                     sampai statusnya diubah manual (mis. klien baru memasang WP).
 * error             : scan terakhir gagal (binary hilang, timeout, JSON rusak);
 *                     tetap dicoba ulang jadwal berikutnya.
 * disabled          : dimatikan manual — tidak pernah disentuh scheduler.
 */
enum WpScanSiteStatus: string
{
    case PendingDetection = 'pending_detection';
    case Active = 'active';
    case NonWordpress = 'non_wordpress';
    case Error = 'error';
    case Disabled = 'disabled';

    public function label(): string
    {
        return match ($this) {
            self::PendingDetection => 'Menunggu deteksi',
            self::Active => 'Aktif',
            self::NonWordpress => 'Bukan WordPress',
            self::Error => 'Error',
            self::Disabled => 'Dinonaktifkan',
        };
    }

    public function badgeClass(): string
    {
        return match ($this) {
            self::Active => 'bg-green-100 text-green-700 dark:bg-green-900 dark:text-green-200',
            self::PendingDetection => 'bg-sky-100 text-sky-700 dark:bg-sky-900 dark:text-sky-200',
            self::Error => 'bg-red-100 text-red-700 dark:bg-red-900 dark:text-red-200',
            self::NonWordpress => 'bg-slate-200 text-slate-600 dark:bg-slate-700 dark:text-slate-300',
            self::Disabled => 'bg-amber-100 text-amber-800 dark:bg-amber-900 dark:text-amber-200',
        };
    }

    /** Status yang boleh dipindai scheduler. Error dicoba ulang supaya pulih otomatis. */
    public function isScannable(): bool
    {
        return $this === self::Active || $this === self::Error;
    }
}