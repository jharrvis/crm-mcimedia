<?php

namespace Database\Seeders;

use App\Domains\Access\Models\Role;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class AdminUserSeeder extends Seeder
{
    public function run(): void
    {
        $email = env('ADMIN_EMAIL', 'admin@mcimedia.net');
        $password = env('ADMIN_PASSWORD');

        if (! $password) {
            if (app()->isProduction()) {
                $this->command->error('Set ADMIN_EMAIL dan ADMIN_PASSWORD di .env sebelum menjalankan seeder di production.');

                return;
            }
            $password = 'password'; // dev only
        }

        // Role administrator dibuat oleh RoleSeeder; sediakan bila seeder ini
        // dijalankan sendiri tanpa RoleSeeder (mis. di test).
        $adminRole = Role::firstOrCreate(
            ['name' => 'administrator'],
            ['label' => 'Administrator', 'permissions' => [], 'is_admin' => true]
        );

        $admin = User::firstOrCreate(
            ['email' => $email],
            ['name' => 'Administrator', 'password' => Hash::make($password)]
        );

        // Pastikan akun admin benar-benar punya role administrator (F4-1),
        // termasuk akun lama yang masih tanpa role.
        if ($admin->role_id !== $adminRole->id) {
            $admin->update(['role_id' => $adminRole->id]);
        }

        $this->command->info("Akun admin siap: {$email}");
    }
}
