<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('wp_scan_sites', function (Blueprint $table) {
            $table->timestamp('last_integrity_check_at')->nullable()->after('last_scan_at');
        });
    }

    public function down(): void
    {
        Schema::table('wp_scan_sites', function (Blueprint $table) {
            $table->dropColumn('last_integrity_check_at');
        });
    }
};
