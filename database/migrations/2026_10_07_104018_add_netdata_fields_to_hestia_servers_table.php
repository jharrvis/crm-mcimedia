<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('hestia_servers', function (Blueprint $table) {
            $table->string('netdata_host')->nullable()->after('port');      // host Netdata (bisa beda dari panel Hestia)
            $table->unsignedInteger('netdata_port')->nullable()->after('netdata_host'); // port Netdata (default 19999)
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('hestia_servers', function (Blueprint $table) {
            $table->dropColumn(['netdata_host', 'netdata_port']);
        });
    }
};