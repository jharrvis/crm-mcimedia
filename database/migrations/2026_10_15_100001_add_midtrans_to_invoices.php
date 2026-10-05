<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->string('midtrans_order_id')->nullable()->unique()->after('public_token');
            $table->string('midtrans_snap_token', 500)->nullable()->after('midtrans_order_id');
            $table->string('midtrans_transaction_status', 50)->nullable()->after('midtrans_snap_token');
        });
    }

    public function down(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->dropColumn(['midtrans_order_id', 'midtrans_snap_token', 'midtrans_transaction_status']);
        });
    }
};
