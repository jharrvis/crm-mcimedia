<?php

/**
 * Seed data realistis untuk smoke test UI F4-13 (khusus lingkungan dev).
 * Mengisi akun Hestia dengan kombinasi paket/kuota/status yang bermakna
 * supaya tampilan bar, persentase, lencana, dan filter bisa diperiksa mata.
 *
 * Jalankan: php artisan db:seed --class=HestiaQuotaDemoSeeder
 */

namespace Database\Seeders;

use App\Domains\Hestia\Models\HestiaAccount;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;

class HestiaQuotaDemoSeeder extends Seeder
{
    public function run(): void
    {
        $now = Carbon::now();

        $rows = [
            // domain, user, plan, disk_used, disk_quota, suspended, user_suspended, status
            ['lancar.id', 'sg2', 'starter', 180, 1024, false, false, 'active'],
            ['mendekati.com', 'sg2', 'bisnis', 860, 1024, false, false, 'active'],
            ['penuh.net', 'yiari', 'bisnis', 2048, 2048, false, false, 'active'],
            ['melebihi.org', 'yiari', 'pro', 2600, 2048, false, false, 'active'],
            ['tanpabatas.web.id', 'sg2', 'unlimited', 5120, 0, false, false, 'active'],
            ['disuspend.com', 'pa-salatiga', 'default', 640, 2048, true, false, 'inactive'],
            ['akunsuspend.co.id', 'pa-salatiga', 'default', 120, 2048, false, true, 'active'],
            ['lamapakai.com', 'sg2', 'starter', 900, 1024, false, false, 'active'],
            ['baru saja.test', 'sg2', 'mini', 12, 512, false, false, 'active'],
            ['belumada-kuota.id', 'sg2', 'mini', null, null, false, false, 'active'],
        ];

        foreach ($rows as [$domain, $user, $plan, $used, $quota, $suspended, $userSuspended, $status]) {
            HestiaAccount::updateOrCreate(
                ['external_key' => HestiaAccount::keyFor($user, $domain)],
                [
                    'hestia_user' => $user,
                    'domain' => $domain,
                    'plan' => $plan,
                    'service_type' => 'hosting',
                    'disk_used' => $used,
                    'disk_quota' => $quota,
                    'suspended' => $suspended,
                    'user_suspended' => $userSuspended,
                    'status' => $status,
                    'mapping_status' => $domain === 'baru saja.test' ? 'unmapped' : 'auto',
                    'start_date' => $now->subDays(120)->toDateString(),
                    'first_seen_at' => $now->subDays(120),
                    'last_seen_at' => $now->subMinutes(7),
                    'raw' => ['U_DISK' => (string) ($used ?? ''), 'SUSPENDED' => $suspended ? 'yes' : 'no'],
                ]
            );
        }

        $this->command?->info('F4-13 demo: '.count($rows).' akun Hestia diisi.');
    }
}
