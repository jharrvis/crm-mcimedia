<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('security_reports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('client_id')->constrained()->cascadeOnDelete();
            $table->string('period', 7);              // YYYY-MM
            $table->string('file_path')->nullable();  // PDF di disk privat (storage)
            $table->string('status', 20)->default('draft'); // draft|sent
            $table->timestamp('sent_at')->nullable();
            $table->timestamps();

            $table->index(['client_id', 'period']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('security_reports');
    }
};
