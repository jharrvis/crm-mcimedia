<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * F4-10: termin pembayaran — satu nilai kontrak (invoice induk) dipecah menjadi
 * beberapa invoice termin (mis. 30%/30%/40%) dengan jatuh tempo masing-masing.
 *
 * Kolom invoices.parent_invoice_id membuat invoice termin tetap berupa invoice
 * biasa (punya nomor, status, pembayaran, PDF, tautan publik sendiri) sekaligus
 * terhubung ke invoice induknya. Kolom invoices.termin_percent menyimpan
 * persentase termin terhadap nilai kontrak untuk keperluan display & audit.
 *
 * invoices.parent_invoice_id NULL = invoice biasa (tidak ikut termin).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            // Relasi diri: invoice termin -> invoice induk. cascadeOnDelete
            // karena menghapus invoice induk harus ikut menghapus terminnya,
            // supaya tidak ada invoice yatim yang tidak bisa ditelusuri.
            // Tabel yang dirujuk disebut eksplisit ('invoices'), bukan
            // disimpulkan dari nama kolom (parent_invoice).
            $table->foreignId('parent_invoice_id')
                ->nullable()
                ->after('client_id')
                ->constrained('invoices')
                ->cascadeOnDelete();

            // Persentase termin terhadap nilai kontrak (30.00 = 30%).
            // NULL untuk invoice biasa & invoice induk.
            $table->decimal('termin_percent', 5, 2)->nullable()->after('parent_invoice_id');

            $table->index(['parent_invoice_id', 'due_date']);
        });
    }

    public function down(): void
    {
        // PENTING: indeks komposit harus DILEPAS lebih dulu, terpisah dari
        // dropColumn. SQLite tidak bisa drop kolom yang masih bagian dari
        // indeks ("error in index ... after drop column: no such column"),
        // sedangkan MySQL/DbForge menghapusnya dalam satu ALTER TABLE. Dua
        // blok terpisah aman di semua driver.
        Schema::table('invoices', function (Blueprint $table) {
            $table->dropIndex(['parent_invoice_id', 'due_date']);
        });

        Schema::table('invoices', function (Blueprint $table) {
            $table->dropForeign(['parent_invoice_id']);
            $table->dropColumn(['parent_invoice_id', 'termin_percent']);
        });
    }
};
