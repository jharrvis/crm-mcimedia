<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->string('pakasir_txn_id')->nullable()->after('midtrans_transaction_status');
            $table->string('pakasir_status', 20)->nullable()->after('pakasir_txn_id');
        });
    }

    public function down(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->dropColumn(['pakasir_txn_id', 'pakasir_status']);
        });
    }
};
