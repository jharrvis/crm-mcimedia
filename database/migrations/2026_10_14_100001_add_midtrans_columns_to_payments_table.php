<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Kolom upstream untuk pelacakan transaksi Midtrans Snap. Semua nullable
     * sehingga pembayaran lewat transfer bank (F2-4) tidak terpengaruh.
     */
    public function up(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->string('upstream_order_id', 64)->nullable()->unique()->after('sender_name');
            $table->string('snap_token', 64)->nullable()->after('upstream_order_id');
            $table->string('upstream_transaction_status', 32)->nullable()->after('snap_token');
            $table->string('upstream_transaction_id')->nullable()->after('upstream_transaction_status');
            $table->string('upstream_payment_type', 32)->nullable()->after('upstream_transaction_id');
            $table->json('upstream_raw_response')->nullable()->after('upstream_payment_type');
        });
    }

    public function down(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->dropUnique(['upstream_order_id']);
            $table->dropColumn([
                'upstream_order_id',
                'snap_token',
                'upstream_transaction_status',
                'upstream_transaction_id',
                'upstream_payment_type',
                'upstream_raw_response',
            ]);
        });
    }
};
