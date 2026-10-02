<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Papan kanban F3-4 menambah status `in_progress` (11 karakter) sedangkan
 * kolom lama hanya varchar(8). Migrasi ini aditif (hanya melebarkan kolom
 * + default tetap 'open'), nilai lama `open`/`done` tetap valid.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tasks', function (Blueprint $table) {
            $table->string('status', 16)->default('open')->change();
        });
    }

    public function down(): void
    {
        Schema::table('tasks', function (Blueprint $table) {
            $table->string('status', 8)->default('open')->change();
        });
    }
};
