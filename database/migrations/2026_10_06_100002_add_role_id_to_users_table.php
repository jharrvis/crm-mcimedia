<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Tambah kolom role_id pada users (F4-1).
     *
     * Aditif & nullable agar akun lama tetap valid. Akun tanpa role dianggap
     * akun warisan (akses penuh) — lihat App\Models\User::isAdmin(). FK memakai
     * restrictOnDelete supaya sebuah role tidak bisa terhapus selama masih
     * dipakai user (cegah user tanpa sengaja jadi akses penuh).
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->foreignId('role_id')
                ->nullable()
                ->after('id')
                ->constrained('roles')
                ->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropConstrainedForeignId('role_id');
        });
    }
};
