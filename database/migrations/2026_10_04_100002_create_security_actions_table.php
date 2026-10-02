<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('security_actions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('client_id')->constrained()->cascadeOnDelete();
            $table->date('acted_at');
            $table->text('action');
            $table->string('performed_by')->nullable();
            $table->text('result')->nullable();
            $table->timestamps();

            $table->index(['client_id', 'acted_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('security_actions');
    }
};
