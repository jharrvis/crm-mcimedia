<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('services', function (Blueprint $table) {
            // Produk katalog yang mendasari layanan ini (opsional). Dipakai
            // invoice perpanjangan otomatis untuk menarik harga terbaru dari
            // katalog, bukan dari snapshot `services.price` yang bisa basi.
            // nullOnDelete: produk dihapus dari katalog tidak boleh ikut
            // menghapus layanan yang sudah berjalan.
            $table->foreignId('product_id')
                ->nullable()
                ->after('parent_id')
                ->constrained('products')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('services', function (Blueprint $table) {
            $table->dropConstrainedForeignId('product_id');
        });
    }
};
