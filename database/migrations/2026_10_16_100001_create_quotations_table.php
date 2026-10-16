<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('quotations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('client_id')->constrained()->cascadeOnDelete();
            $table->string('number')->unique();          // QUO-YYYYMM-####
            $table->string('title')->nullable();
            $table->date('issue_date');
            $table->date('valid_until');                 // masa berlaku penawaran
            $table->string('status', 16)->default('draft'); // draft|sent|accepted|rejected|expired|converted
            $table->unsignedBigInteger('subtotal')->default(0); // IDR, integer
            $table->unsignedBigInteger('discount')->default(0); // IDR, integer (boleh 0)
            $table->unsignedBigInteger('total')->default(0);    // IDR, integer, tanpa PPN, clamp >= 0
            $table->text('notes')->nullable();
            $table->text('terms')->nullable();           // syarat & ketentuan
            $table->string('public_token', 64)->unique()->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('responded_at')->nullable();
            $table->foreignId('converted_invoice_id')->nullable()->constrained('invoices')->nullOnDelete();
            $table->timestamps();

            $table->index('valid_until');
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('quotations');
    }
};
