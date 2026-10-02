<?php

namespace Database\Seeders;

use App\Domains\Access\Enums\AccessLevel;
use App\Domains\Access\Models\Role;
use Illuminate\Database\Seeder;

/**
 * Role bawaan (F4-1). Idempotent — aman dijalankan berulang.
 *
 * - administrator: is_admin, seluruh modul terbuka, tidak bisa dihapus/diturunkan.
 * - staf: operator harian (kelola data operasional, tanpa pengaturan user/role).
 */
class RoleSeeder extends Seeder
{
    public function run(): void
    {
        Role::updateOrCreate(
            ['name' => 'administrator'],
            [
                'label' => 'Administrator',
                'description' => 'Akses penuh ke seluruh modul, termasuk pengaturan pengguna dan role.',
                'permissions' => [],
                'is_admin' => true,
            ]
        );

        Role::updateOrCreate(
            ['name' => 'staf'],
            [
                'label' => 'Staf',
                'description' => 'Mengelola data operasional tanpa akses pengaturan pengguna/role.',
                'permissions' => [
                    'clients' => AccessLevel::Manage->value,
                    'services' => AccessLevel::Manage->value,
                    'invoices' => AccessLevel::Manage->value,
                    'products' => AccessLevel::Manage->value,
                    'projects' => AccessLevel::Manage->value,
                    'tasks' => AccessLevel::Manage->value,
                    'reports' => AccessLevel::View->value,
                    'security' => AccessLevel::Manage->value,
                    'hestia' => AccessLevel::View->value,
                    'activity' => AccessLevel::View->value,
                    'reminders' => AccessLevel::View->value,
                ],
                'is_admin' => false,
            ]
        );
    }
}
