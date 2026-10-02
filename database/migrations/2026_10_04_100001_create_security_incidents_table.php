<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('security_incidents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('client_id')->constrained()->cascadeOnDelete();
            // ID dari script monitoring untuk idempotency (opsional untuk input manual).
            $table->string('external_id', 191)->nullable();
            $table->timestamp('occurred_at');
            $table->string('severity', 20)->index();  // critical|high|medium|low|info
            $table->string('source', 30);             // firewall|wpscan|file-integrity|monitor|manual
            $table->string('title');
            $table->text('description')->nullable();
            $table->string('status', 20)->default('open')->index(); // open|mitigated|resolved
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();

            // Satu external_id unik per klien (NULL boleh berulang → input manual).
            $table->unique(['client_id', 'external_id']);
            $table->index(['client_id', 'occurred_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('security_incidents');
    }
};
