<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * F4-8: invoice gabungan — satu invoice bisa mencakup banyak layanan sekaligus
 * (mis. klien dengan banyak website yang membayar bulanan dalam satu invoice).
 *
 * Kolom invoices.service_id (relasi 1-1 ke satu layanan) digantikan tabel pivot
 * invoice_service. Data lama dipindahkan ke pivot lebih dulu, lalu kolom
 * service_id beserta foreign key-nya dihapus.
 */
return new class extends Migration
{
    public function up(): void
    {
        // PENTING: data lama harus dibaca SEBELUM perubahan skema. SQLite tidak
        // bisa drop kolom via ALTER TABLE bila ada foreign key, jadi Laravel
        // membangun ulang tabel invoices (drop + re-insert). Karena
        // invoice_service memakai ON DELETE CASCADE ke invoices, baris pivot
        // yang baru dibuat akan ikut terhapus saat rebuild. Jadi: snapshot dulu,
        // baru ubah skema, baru tulis ulang ke pivot.
        $legacy = DB::table('invoices')
            ->whereNotNull('service_id')
            ->orderBy('id')
            ->get(['id', 'service_id']);

        Schema::create('invoice_service', function (Blueprint $table) {
            $table->foreignId('invoice_id')->constrained()->cascadeOnDelete();
            $table->foreignId('service_id')->constrained()->cascadeOnDelete();

            // Satu layanan hanya boleh muncul sekali dalam satu invoice.
            $table->primary(['invoice_id', 'service_id']);
        });

        Schema::table('invoices', function (Blueprint $table) {
            $table->dropForeign(['service_id']);
            $table->dropColumn('service_id');
        });

        foreach ($legacy->chunk(500) as $chunk) {
            DB::table('invoice_service')->insertOrIgnore(
                $chunk->map(fn ($row) => [
                    'invoice_id' => $row->id,
                    'service_id' => $row->service_id,
                ])->all()
            );
        }
    }

    public function down(): void
    {
        // PENTING: data pivot harus dibaca SEBELUM kolom service_id dikembalikan.
        // SQLite tidak bisa menambah foreign key via ALTER TABLE, jadi Laravel
        // membangun ulang tabel invoices (drop + re-insert). Karena
        // invoice_service memakai ON DELETE CASCADE ke invoices, baris pivot
        // ikut terhapus saat rebuild — dibaca setelah itu akan selalu kosong.
        $links = DB::table('invoice_service')
            ->orderBy('invoice_id')
            ->orderBy('service_id')
            ->get(['invoice_id', 'service_id']);

        Schema::table('invoices', function (Blueprint $table) {
            $table->foreignId('service_id')->nullable()->constrained()->nullOnDelete();
        });

        // Kembalikan hanya satu layanan per invoice (yang pertama) ke kolom lama.
        foreach ($links as $link) {
            DB::table('invoices')
                ->where('id', $link->invoice_id)
                ->whereNull('service_id')
                ->update(['service_id' => $link->service_id]);
        }

        Schema::dropIfExists('invoice_service');
    }
};
