<?php

namespace Database\Seeders;

use App\Domains\Hestia\Models\HestiaServer;
use Illuminate\Database\Seeder;

/**
 * Server HestiaCP awal untuk kasus MCI Media (F4-12): sg2, YIARI, PA Salatiga.
 *
 * SENGAJA TIDAK berisi host panel/kredensial API — tidak ada data rahasia di
 * repo; admin mengisi lewat UI `/hestia/servers`. Alamat Netdata (tailnet) AMAN
 * untuk di-seed karena hanya bisa diakses via jaringan internal.
 *
 * Server dibuat `is_active = true` dengan netdata_host terisi agar langsung
 * muncul di halaman Monitoring Server. Sync Hestia manual per tombol dan akan
 * gagal dengan pesan jelas bila kredensial belum diisi.
 *
 * Idempotent & NON-DESTRUKTIF: memakai `firstOrCreate` per `code`. Menjalankan
 * ulang TIDAK mengubah server yang sudah ada.
 */
class HestiaServerSeeder extends Seeder
{
    /** @var array<int, array{code: string, name: string, netdata_host: string, notes: string}> */
    private const SERVERS = [
        ['code' => 'sg2', 'name' => 'sg2', 'netdata_host' => '100.119.156.82', 'notes' => 'Server HestiaCP sg2 — panel & kredensial diisi admin.'],
        ['code' => 'yiari', 'name' => 'YIARI', 'netdata_host' => '100.114.35.33', 'notes' => 'Server HestiaCP YIARI — panel & kredensial diisi admin.'],
        ['code' => 'pa-salatiga', 'name' => 'PA Salatiga', 'netdata_host' => '100.97.142.93', 'notes' => 'Server HestiaCP PA Salatiga — panel & kredensial diisi admin.'],
    ];

    public function run(): void
    {
        foreach (self::SERVERS as $server) {
            HestiaServer::firstOrCreate(
                ['code' => $server['code']],
                [
                    'name' => $server['name'],
                    'host' => '',
                    'is_active' => true,
                    'netdata_host' => $server['netdata_host'],
                    'netdata_port' => 19999,
                    'notes' => $server['notes'],
                ],
            );
        }
    }
}
