<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Tabel role + hak akses per modul (F4-1).
     *
     * permissions disimpan sebagai JSON: { "clients": "manage", "invoices": "view", ... }
     * Level yang dikenal: "view" (hanya lihat) dan "manage" (lihat + kelola).
     * Modul yang tidak ada di map = tidak ada akses.
     */
    public function up(): void
    {
        Schema::create('roles', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->string('label');
            $table->string('description')->nullable();
            $table->json('permissions')->nullable();
            $table->boolean('is_admin')->default(false);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('roles');
    }
};
