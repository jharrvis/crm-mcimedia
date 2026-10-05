<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Status alert kuota disk per akun Hestia (t_afef420a).
 *
 * `disk_alert_level` menyimpan level alert TERAKHIR yang sudah diberitahukan
 * (none|warning|critical) sehingga command `crm:disk-quota-alerts` idempoten:
 * tidak ada duplikat insiden/notifikasi selama level tidak berubah.
 * `disk_alert_at` = waktu terakhir level berubah (untuk audit & UI).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('hestia_accounts', function (Blueprint $table) {
            $table->string('disk_alert_level', 10)->default('none')->after('disk_quota')->index();
            $table->timestamp('disk_alert_at')->nullable()->after('disk_alert_level');
        });
    }

    public function down(): void
    {
        Schema::table('hestia_accounts', function (Blueprint $table) {
            $table->dropIndex(['disk_alert_level']);
            $table->dropColumn(['disk_alert_level', 'disk_alert_at']);
        });
    }
};