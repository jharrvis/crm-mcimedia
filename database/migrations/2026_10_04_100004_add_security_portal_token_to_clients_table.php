<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Token tautan laporan keamanan publik per klien (F3-3), pola sama
     * seperti invoices.public_token. NULL = tautan tidak aktif/dicabut.
     */
    public function up(): void
    {
        Schema::table('clients', function (Blueprint $table) {
            $table->string('security_portal_token', 64)->nullable()->unique()->after('is_active');
        });
    }

    public function down(): void
    {
        Schema::table('clients', function (Blueprint $table) {
            $table->dropUnique(['security_portal_token']);
            $table->dropColumn('security_portal_token');
        });
    }
};
