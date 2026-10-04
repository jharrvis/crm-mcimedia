<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Sesi sinkronisasi HestiaCP BERTAHAP (batch) — t_dcccffd9.
 *
 * Satu baris = satu sesi sync yang diproses browser per batch lewat AJAX.
 * State sengaja disimpan di server (bukan di klien) supaya:
 *   - progress & counter bersifat otoritatif,
 *   - batch terakhir bisa menonaktifkan akun yang benar-benar hilang memakai
 *     akumulasi `seen_keys` (bukan sebagian saja),
 *   - request yang timeout boleh diulang tanpa menggandakan pekerjaan
 *     (`next_offset` hanya maju setelah sebuah batch diproses).
 *
 * Menghapus server ikut menghapus sesi (cascade); log riwayat sync tetap
 * tersimpan lewat `hestia_sync_log_id` (nullOnDelete).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('hestia_sync_batches', function (Blueprint $table) {
            $table->id();
            $table->foreignId('hestia_server_id')->constrained('hestia_servers')->cascadeOnDelete();
            $table->foreignId('hestia_sync_log_id')->nullable()->constrained('hestia_sync_logs')->nullOnDelete();
            $table->string('status', 20)->default('running');
            $table->unsignedInteger('total_users')->default(0);
            $table->unsignedInteger('processed_users')->default(0);
            $table->unsignedInteger('next_offset')->default(0);
            $table->unsignedInteger('batch_size')->default(10);
            $table->json('users')->nullable();
            $table->json('seen_keys')->nullable();
            $table->json('errors')->nullable();
            $table->unsignedInteger('pulled')->default(0);
            $table->unsignedInteger('created')->default(0);
            $table->unsignedInteger('updated')->default(0);
            $table->unsignedInteger('deactivated')->default(0);
            $table->unsignedInteger('unmapped')->default(0);
            $table->string('message', 1000)->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();

            $table->index(['hestia_server_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('hestia_sync_batches');
    }
};
