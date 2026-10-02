<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('project_journals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->date('occurred_on'); // tanggal kejadian (bisa diisi mundur)
            $table->string('category', 16)->default('progress'); // progress|note|blocker
            $table->text('body');
            $table->timestamps();

            // Timeline project: urut tanggal lalu waktu input.
            $table->index(['project_id', 'occurred_on']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('project_journals');
    }
};
