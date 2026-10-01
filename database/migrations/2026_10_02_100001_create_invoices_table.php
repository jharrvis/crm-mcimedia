<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('invoices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('client_id')->constrained()->cascadeOnDelete();
            $table->foreignId('service_id')->nullable()->constrained()->nullOnDelete();
            $table->string('number')->unique();          // INV-YYYYMM-####
            $table->string('title')->nullable();
            $table->date('issue_date');
            $table->date('due_date');
            $table->string('status', 16)->default('draft'); // draft|sent|paid|overdue|cancelled
            $table->unsignedBigInteger('subtotal')->default(0); // IDR, integer
            $table->unsignedBigInteger('total')->default(0);    // IDR, integer (tanpa PPN)
            $table->text('notes')->nullable();
            $table->string('public_token', 64)->unique()->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->timestamps();

            $table->index('due_date');
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('invoices');
    }
};
