<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('quotations', function (Blueprint $table) {
            // Penanda pengingat kedaluwarsa H-3 terkirim (idempotency command
            // crm:quotations-expiring Q-2); null = belum dikirim.
            $table->timestamp('expiry_reminder_sent_at')->nullable()->after('responded_at');
        });
    }

    public function down(): void
    {
        Schema::table('quotations', function (Blueprint $table) {
            $table->dropColumn('expiry_reminder_sent_at');
        });
    }
};
