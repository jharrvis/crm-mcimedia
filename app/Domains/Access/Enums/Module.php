<?php

namespace App\Domains\Access\Enums;

/**
 * Daftar modul CRM yang bisa diatur hak aksesnya per role (F4-1).
 *
 * Satu tempat sebagai sumber kebenaran: label sidebar, form role, dan
 * pemetaan route → modul (middleware permission:<module>).
 */
enum Module: string
{
    case Clients = 'clients';
    case Services = 'services';
    case Invoices = 'invoices';
    case Products = 'products';
    case Projects = 'projects';
    case Tasks = 'tasks';
    case Reports = 'reports';
    case Security = 'security';
    case Hestia = 'hestia';
    case Activity = 'activity';
    case Reminders = 'reminders';
    case Users = 'users';
    case Roles = 'roles';

    public function label(): string
    {
        return match ($this) {
            self::Clients => 'Klien',
            self::Services => 'Layanan',
            self::Invoices => 'Invoice',
            self::Products => 'Produk',
            self::Projects => 'Project',
            self::Tasks => 'Tugas',
            self::Reports => 'Laporan',
            self::Security => 'Keamanan',
            self::Hestia => 'Sinkron Hestia',
            self::Activity => 'Aktivitas',
            self::Reminders => 'Pengingat',
            self::Users => 'Pengguna',
            self::Roles => 'Role',
        };
    }

    /**
     * Apakah modul mendukung aksi tulis (create/update/delete). Modul laporan
     * murni baca sehingga hanya punya level "lihat".
     */
    public function supportsManage(): bool
    {
        return ! in_array($this, [self::Reports, self::Activity, self::Reminders], true);
    }

    /**
     * Modul yang dapat diatur di form role. Dashboard selalu bisa diakses
     * semua user yang login, jadi tidak ikut dikonfigurasi.
     *
     * @return array<int, self>
     */
    public static function configurable(): array
    {
        return self::cases();
    }

    /**
     * @return array<string, string> map value => label
     */
    public static function labels(): array
    {
        $labels = [];

        foreach (self::cases() as $module) {
            $labels[$module->value] = $module->label();
        }

        return $labels;
    }
}
