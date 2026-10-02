<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('achievement_reports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->string('period_type', 8)->default('month'); // week|month
            $table->date('period_start');
            $table->date('period_end');
            // Ringkasan otomatis dari data periode (task selesai + jurnal).
            $table->text('summary')->nullable();
            // Narasi manual yang ditulis pengguna.
            $table->text('narrative')->nullable();
            $table->timestamp('generated_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            // Satu laporan per project per periode (idempotent saat generate ulang).
            $table->unique(['project_id', 'period_type', 'period_start', 'period_end'], 'achievement_reports_period_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('achievement_reports');
    }
};
