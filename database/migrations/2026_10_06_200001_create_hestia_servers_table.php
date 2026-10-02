<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Server HestiaCP yang dikelola dari UI (F4-12).
     *
     * Satu baris = satu server HestiaCP (mis. sg2, YIARI, PA Salatiga) yang
     * disinkronkan ke CRM. Parameter koneksi non-rahasia (host/port/scheme/
     * verify_ssl/timeout) disimpan sebagai kolom biasa agar bisa dicari &
     * ditampilkan; KREDENSIAL (`user`, `password`, `access_key`, `secret_key`)
     * disimpan pada kolom `credentials` sebagai JSON **terenkripsi** (cast
     * `encrypted:array` di model) — tidak pernah ditampilkan utuh di UI, tidak
     * pernah ditulis ke log.
     *
     * `code` adalah pengenal stabil & unik (slug) yang dipakai pada kunci
     * eksternal akun (`srv:<code>:dom:<user>:<domain>`), sehingga sinkronisasi
     * tetap idempotent meski label server diubah.
     *
     * Kolom `is_active` menentukan apakah server ikut dijadwalkan sync harian.
     */
    public function up(): void
    {
        Schema::create('hestia_servers', function (Blueprint $table) {
            $table->id();
            $table->string('name');                       // label untuk admin, mis. "sg2"
            $table->string('code')->unique();             // slug stabil untuk external_key
            $table->string('host');                       // hostname atau URL panel
            $table->unsignedInteger('port')->default(8083);
            $table->string('scheme', 8)->default('https');
            $table->boolean('verify_ssl')->default(false); // API Hestia biasanya self-signed
            $table->unsignedInteger('timeout')->default(30);
            $table->text('credentials')->nullable();      // JSON terenkripsi (lihat model)
            $table->boolean('is_active')->default(true);   // ikut sync terjadwal atau tidak
            $table->string('notes')->nullable();          // catatan bebas admin
            $table->timestamp('last_sync_at')->nullable(); // terakhir sync berhasil
            $table->string('last_sync_status', 16)->nullable(); // success|failed
            $table->timestamp('last_synced_at')->nullable();    // terakhir sync dicoba
            $table->text('last_sync_message')->nullable();
            $table->timestamps();

            $table->index('is_active');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('hestia_servers');
    }
};
