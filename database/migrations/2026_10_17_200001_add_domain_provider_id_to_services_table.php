<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Asal domain dari provider/registrar (t_ee922ded).
     *
     * Sebelumnya hubungan ini hanya tersirat di teks `services.notes`
     * ("Disinkronkan otomatis dari provider …") sehingga halaman daftar domain
     * tidak bisa memfilter maupun mengurutkan per provider. Kolom FK ini
     * memberi sumber kebenaran relasional tanpa mengubah tabel lama.
     *
     * Nullable + `nullOnDelete`: domain yang dicatat manual (atau provider-nya
     * dihapus admin) tetap utuh — domain tidak boleh hilang hanya karena
     * kredensial provider dibersihkan. `ProviderServiceImporter` mengisi kolom
     * ini saat impor dan menyegarkannya HANYA bila masih NULL, jadi hasil sync
     * harian tidak menimpa pemetaan yang sudah(admin) tetapkan.
     */
    public function up(): void
    {
        Schema::table('services', function (Blueprint $table) {
            $table->foreignId('domain_provider_id')
                ->nullable()
                ->after('product_id')
                ->constrained('domain_providers')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('services', function (Blueprint $table) {
            $table->dropConstrainedForeignId('domain_provider_id');
        });
    }
};
