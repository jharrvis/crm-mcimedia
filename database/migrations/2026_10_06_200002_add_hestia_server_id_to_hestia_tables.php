<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Skop `hestia_servers` pada akun & riwayat sinkronisasi (F4-12).
     *
     * Kolom nullable + TANPA writeback ke data lama: akun hasil sinkronisasi
     * F3-1 (yang belum punya server) tetap `hestia_server_id = NULL` dan
     * dikendalikan lewat path environment lama — nol risiko pada data existing.
     *
     * Akun yang sudah punya `external_key` berawalan `dom:` (F3-1) TIDAK diubah:
     * kunci tersebut tetap dipakai path env. Akun dari server baru memakai kunci
     * `srv:<code>:dom:...` sehingga tidak bentrok dengan akun lama.
     *
     * `nullOnDelete` dipilih agar menghapus server tidak menghapus akun/layanan
     * klien — sama seperti prinsip F3-1 "baris tidak pernah dihapus".
     */
    public function up(): void
    {
        Schema::table('hestia_accounts', function (Blueprint $table) {
            $table->foreignId('hestia_server_id')
                ->nullable()
                ->after('external_key')
                ->constrained('hestia_servers')
                ->nullOnDelete();
        });

        Schema::table('hestia_sync_logs', function (Blueprint $table) {
            $table->foreignId('hestia_server_id')
                ->nullable()
                ->after('id')
                ->constrained('hestia_servers')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('hestia_sync_logs', function (Blueprint $table) {
            $table->dropConstrainedForeignId('hestia_server_id');
        });

        Schema::table('hestia_accounts', function (Blueprint $table) {
            $table->dropConstrainedForeignId('hestia_server_id');
        });
    }
};
