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
        Schema::create('ssl_certificates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('client_id')->constrained()->cascadeOnDelete();
            $table->string('domain', 255);
            $table->string('issuer', 255)->nullable();
            $table->dateTime('expires_at')->index();
            $table->string('status', 16)->default('valid'); // valid, expired, expiring
            $table->json('san_domains')->nullable();
            $table->dateTime('last_checked_at')->nullable();
            $table->timestamps();

            $table->unique(['client_id', 'domain']);
            $table->index(['client_id', 'status']);
            $table->index(['expires_at', 'status']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('ssl_certificates');
    }
};