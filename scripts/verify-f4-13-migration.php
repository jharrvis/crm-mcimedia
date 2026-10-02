<?php

/**
 * Verifikasi migrasi F4-13 di file SQLite nyata (bukan :memory:).
 *
 * Menjalankan up() → backfill → up() lagi (idempotent) → down() → up(),
 * lalu memeriksa kolom & isi. Jalankan:
 *   php scripts/verify-f4-13-migration.php
 */

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

require __DIR__.'/../vendor/autoload.php';

$app = require_once __DIR__.'/../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$failures = [];

function check(string $label, bool $ok, string $detail = ''): void
{
    global $failures;

    if (! $ok) {
        $failures[] = $label.($detail !== '' ? " — {$detail}" : '');
        echo "  FAIL  {$label}".($detail !== '' ? " ({$detail})" : '')."\n";

        return;
    }

    echo "  ok    {$label}\n";
}

$database = __DIR__.'/verify-f4-13.sqlite';
if (is_file($database)) {
    unlink($database);
}
touch($database);

config(['database.connections.sqlite.database' => $database]);
DB::purge('sqlite');
DB::reconnect('sqlite');

// Bangun skema dasar (hanya tabel F3-1 yang relevan) + isi data "sebelum upgrade".
Schema::create('hestia_accounts', function ($table): void {
    $table->id();
    $table->string('external_key')->unique();
    $table->string('hestia_user', 64);
    $table->string('domain');
    $table->string('plan')->nullable();
    $table->string('service_type', 32)->default('hosting');
    $table->date('start_date')->nullable();
    $table->date('end_date')->nullable();
    $table->string('status', 16)->default('active');
    $table->string('mapping_status', 16)->default('unmapped');
    $table->json('raw')->nullable();
    $table->timestamp('first_seen_at')->nullable();
    $table->timestamp('last_seen_at')->nullable();
    $table->timestamps();
});

$before = [
    ['key' => 'dom:u:a.com', 'raw' => ['IP' => '203.0.113.10', 'U_DISK' => '512', 'SUSPENDED' => 'no'], 'plan' => 'default'],
    ['key' => 'dom:u:b.com', 'raw' => ['IP' => '203.0.113.10', 'U_DISK' => '2048', 'SUSPENDED' => 'yes'], 'plan' => 'pro'],
    // Tidak ada U_DISK/SUSPENDED (Hestia lama) → kolom harus tetap null/false.
    ['key' => 'dom:u:c.com', 'raw' => ['IP' => '203.0.113.10'], 'plan' => 'default'],
    // Nilai rusak → tidak boleh membuat baris gagal.
    ['key' => 'dom:u:d.com', 'raw' => ['U_DISK' => 'unlimited', 'SUSPENDED' => 'mungkin'], 'plan' => 'default'],
    // Tanpa payload sama sekali.
    ['key' => 'dom:u:e.com', 'raw' => null, 'plan' => 'default'],
];

foreach ($before as $row) {
    DB::table('hestia_accounts')->insert([
        'external_key' => $row['key'],
        'hestia_user' => 'u',
        'domain' => Str::after($row['key'], 'dom:u:'),
        'plan' => $row['plan'],
        'raw' => $row['raw'] === null ? null : json_encode($row['raw']),
        'status' => 'active',
        'mapping_status' => 'mapped',
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

$migration = require __DIR__.'/../database/migrations/2026_10_07_200001_add_quota_columns_to_hestia_accounts_table.php';

echo "== up() + backfill ==\n";
$migration->up();

foreach (['disk_used', 'disk_quota', 'suspended', 'user_suspended'] as $column) {
    check("kolom {$column} ada", Schema::hasColumn('hestia_accounts', $column));
}

$rows = DB::table('hestia_accounts')->orderBy('id')->get()->keyBy('external_key');

check('a.com: U_DISK 512 → disk_used 512', $rows['dom:u:a.com']->disk_used === 512, var_export($rows['dom:u:a.com']->disk_used, true));
check('a.com: SUSPENDED=no → suspended 0', (int) $rows['dom:u:a.com']->suspended === 0);
check('a.com: disk_quota null (tidak ada di payload domain)', $rows['dom:u:a.com']->disk_quota === null);
check('a.com: user_suspended default 0', (int) $rows['dom:u:a.com']->user_suspended === 0);

check('b.com: U_DISK 2048 → disk_used 2048', $rows['dom:u:b.com']->disk_used === 2048);
check('b.com: SUSPENDED=yes → suspended 1', (int) $rows['dom:u:b.com']->suspended === 1);

check('c.com: tanpa U_DISK → disk_used tetap null', $rows['dom:u:c.com']->disk_used === null);
check('c.com: tanpa SUSPENDED → suspended default 0', (int) $rows['dom:u:c.com']->suspended === 0);

check('d.com: U_DISK "unlimited" → disk_used null', $rows['dom:u:d.com']->disk_used === null, var_export($rows['dom:u:d.com']->disk_used, true));
check('d.com: SUSPENDED "mungkin" → suspended default 0 (tidak ditebak yes)', (int) $rows['dom:u:d.com']->suspended === 0);

check('e.com: raw null → baris tetap utuh', (int) $rows['dom:u:e.com']->disk_used === 0);
check('jumlah baris tidak berubah', DB::table('hestia_accounts')->count() === count($before), DB::table('hestia_accounts')->count().' baris');
check('raw payload tidak dirusak', json_decode($rows['dom:u:a.com']->raw, true)['U_DISK'] === '512');

echo "\n== down() ==\n";
$migration->down();
foreach (['disk_used', 'disk_quota', 'suspended', 'user_suspended'] as $column) {
    check("kolom {$column} hilang", ! Schema::hasColumn('hestia_accounts', $column));
}
check('data lama utuh setelah down()', DB::table('hestia_accounts')->count() === count($before));
check('plan tidak hilang setelah down()', DB::table('hestia_accounts')->where('external_key', 'dom:u:b.com')->value('plan') === 'pro');
check('raw tidak hilang setelah down()', json_decode(DB::table('hestia_accounts')->where('external_key', 'dom:u:a.com')->value('raw'), true)['U_DISK'] === '512');

echo "\n== re-up() setelah rollback ==\n";
$migration->up();
check('backfill berjalan lagi setelah re-up', (int) DB::table('hestia_accounts')->where('external_key', 'dom:u:b.com')->value('disk_used') === 2048);

unlink($database);

echo "\n";
if ($failures === []) {
    echo "MIGRASI F4-13: SEMUA OK\n";
    exit(0);
}

echo 'MIGRASI F4-13 GAGAL: '.count($failures)." pemeriksaan\n";
foreach ($failures as $failure) {
    echo "  - {$failure}\n";
}
exit(1);
