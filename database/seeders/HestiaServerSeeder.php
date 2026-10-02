<?php

namespace Database\Seeders;

use App\Domains\Hestia\Models\HestiaServer;
use Illuminate\Database\Seeder;

/**
 * Server HestiaCP awal untuk kasus MCI Media (F4-12): sg2, YIARI, PA Salatiga.
 *
 * SENGAJA TIDAK berisi host/kredensial — tidak ada data rahasia di repo. Baris
 * dibuat `is_active = false` sehingga tidak ikut dijadwalkan sync sampai admin
 * mengisi host & kredensial lewat UI `/hestia/servers`.
 *
 * Idempotent & NON-DESTRUKTIF: memakai `firstOrCreate` per `code`. Menjalankan
 * ulang TIDAK mengubah server yang sudah ada — khususnya status aktif, host, dan
 * kredensial milik admin tetap utuh. Benih baru yang ditambahkan di kemudian
 * hari tidak akan me-nonaktifkan server yang sudah dikonfigurasi.
 */
class HestiaServerSeeder extends Seeder
{
    /** @var array<int, array{code: string, name: string, notes: string}> */
    private const SERVERS = [
        ['code' => 'sg2', 'name' => 'sg2', 'notes' => 'Server HestiaCP sg2 — panel & kredensial diisi admin.'],
        ['code' => 'yiari', 'name' => 'YIARI', 'notes' => 'Server HestiaCP YIARI — panel & kredensial diisi admin.'],
        ['code' => 'pa-salatiga', 'name' => 'PA Salatiga', 'notes' => 'Server HestiaCP PA Salatiga — panel & kredensial diisi admin.'],
    ];

    public function run(): void
    {
        foreach (self::SERVERS as $server) {
            HestiaServer::firstOrCreate(
                ['code' => $server['code']],
                [
                    'name' => $server['name'],
                    'host' => '',
                    'is_active' => false,
                    'notes' => $server['notes'],
                ],
            );
        }
    }
}
