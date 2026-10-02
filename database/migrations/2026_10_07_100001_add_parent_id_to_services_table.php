<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Konsep parent-child untuk layanan domain (F4-9).
     *
     * `parent_id` menunjuk ke `services` lain (domain induk) sehingga subdomain
     * dapat dikelompokkan di bawah domain induknya — satu level atau lebih.
     * Hanya layanan berjenis `domain` yang boleh punya induk (ditegakkan di
     * ServiceRequest, bukan di DB, supaya pesannya ramah untuk admin).
     *
     * `nullOnDelete`: menghapus domain induk TIDAK ikut menghapus subdomain —
     * subdomain-nya tetap ada sebagai layanan mandiri (parent_id -> NULL).
     * `cascadeOnDelete` di sini justru destruktif: satu klik hapus pada domain
     * induk bisa menghapus puluhan subdomain yang tidak terlihat.
     */
    public function up(): void
    {
        Schema::table('services', function (Blueprint $table) {
            $table->foreignId('parent_id')
                ->nullable()
                ->after('client_id')
                ->constrained('services')
                ->nullOnDelete();

            $table->index(['parent_id', 'end_date']);
        });
    }

    public function down(): void
    {
        Schema::table('services', function (Blueprint $table) {
            $table->dropForeign(['parent_id']);
            $table->dropIndex(['parent_id', 'end_date']);
            $table->dropColumn('parent_id');
        });
    }
};
