<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('monitoring_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('client_id')->constrained()->cascadeOnDelete();
            $table->string('external_id', 64)->unique(); // SHA256 hash
            $table->dateTime('occurred_at')->index();
            $table->string('type', 32); // waf_block, brute_force, rate_limit
            $table->string('ip', 45); // IPv4 or IPv6
            $table->string('target_url', 500);
            $table->string('country', 2)->nullable(); // ISO 3166-1 alpha-2
            $table->string('severity', 16); // critical, high, medium, low, info
            $table->json('details')->nullable();
            $table->timestamps();

            $table->index(['client_id', 'occurred_at']);
            $table->index(['client_id', 'type']);
            $table->index(['client_id', 'severity']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('monitoring_events');
    }
};