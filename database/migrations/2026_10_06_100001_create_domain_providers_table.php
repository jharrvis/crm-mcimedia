<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Registry penyedia (provider) domain/hosting (F4-5).
     *
     * Satu baris = satu kredensial penyedia yang dikelola admin dari UI.
     * Kolom `driver` menyimpan KUNCI driver (mis. `hestia`, `manual`) yang
     * memetakan ke kelas driver lewat config `crm.domain_providers.drivers`.
     *
     * Kredensial disimpan pada kolom `credentials` sebagai JSON terenkripsi
     * (cast `encrypted:array` di model) — bentuk isinya bergantung pada driver
     * itu sendiri (`credentialFields()`), sehingga menambah driver baru TIDAK
     * memerlukan perubahan skema tabel ini (extensible by design).
     */
    public function up(): void
    {
        Schema::create('domain_providers', function (Blueprint $table) {
            $table->id();
            $table->string('name');                          // label ramah untuk admin
            $table->string('driver', 64);                    // kunci driver (hestia|manual|...)
            $table->text('credentials')->nullable();         // JSON terenkripsi (lihat model)
            $table->boolean('is_active')->default(true);     // aktif/nonaktif
            $table->string('notes')->nullable();             // catatan bebas admin
            $table->timestamp('last_used_at')->nullable();   // terakhir dipakai list/getExpiry
            $table->timestamps();

            $table->index('driver');
            $table->index('is_active');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('domain_providers');
    }
};
