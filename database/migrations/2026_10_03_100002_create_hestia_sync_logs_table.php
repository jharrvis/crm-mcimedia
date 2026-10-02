<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Riwayat/audit setiap proses sinkronisasi HestiaCP (F3-1).
     * Menyimpan ringkasan jumlah akun yang ditarik/dibuat/diperbarui/
     * dinonaktifkan, plus status & pesan bila gagal. Kredensial TIDAK pernah
     * ditulis ke tabel ini.
     */
    public function up(): void
    {
        Schema::create('hestia_sync_logs', function (Blueprint $table) {
            $table->id();
            $table->string('status', 16);            // running|success|failed
            $table->unsignedInteger('pulled')->default(0);
            $table->unsignedInteger('created')->default(0);
            $table->unsignedInteger('updated')->default(0);
            $table->unsignedInteger('deactivated')->default(0);
            $table->unsignedInteger('unmapped')->default(0);
            $table->text('message')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('hestia_sync_logs');
    }
};
