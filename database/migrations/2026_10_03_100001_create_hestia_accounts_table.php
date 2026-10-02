<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Akun hasil sinkronisasi HestiaCP (F3-1).
     *
     * Satu baris = satu web domain Hestia (per user/akun hosting). Tabel ini
     * menyimpan provenance (asal-usul) sekaligus status pemetaan ke klien, agar
     * sinkronisasi berulang bersifat idempotent dan akun yang tidak cocok dapat
     * dipetakan manual oleh admin. Akun yang hilang dari Hestia ditandai
     * `status = inactive` (baris TIDAK pernah dihapus).
     */
    public function up(): void
    {
        Schema::create('hestia_accounts', function (Blueprint $table) {
            $table->id();
            $table->string('external_key')->unique();   // kunci stabil, mis. dom:<user>:<domain>
            $table->string('hestia_user', 64);          // akun Hestia pemilik domain
            $table->string('domain');                   // web domain
            $table->string('plan')->nullable();         // paket/plan Hestia (PACKAGE) bila tersedia
            $table->string('service_type', 32)->default('hosting'); // jenis Service saat dipetakan
            $table->date('start_date')->nullable();     // tanggal dibuat di Hestia (bila ada)
            $table->date('end_date')->nullable();       // tidak disediakan Hestia untuk web domain
            $table->string('status', 16)->default('active');         // active|inactive
            $table->string('mapping_status', 16)->default('unmapped'); // auto|mapped|unmapped|ignored
            $table->foreignId('client_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('service_id')->nullable()->constrained('services')->nullOnDelete();
            $table->json('raw')->nullable();            // payload mentah Hestia (untuk audit)
            $table->timestamp('first_seen_at')->nullable();
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamps();

            $table->index('mapping_status');
            $table->index('status');
            $table->index('hestia_user');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('hestia_accounts');
    }
};
