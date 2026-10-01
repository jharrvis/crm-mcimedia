<?php

namespace Database\Seeders;

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

        User::firstOrCreate(
            ['email' => $email],
            ['name' => 'Administrator', 'password' => Hash::make($password)]
        );

        $this->command->info("Akun admin siap: {$email}");
    }
}
