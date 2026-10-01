<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('services', function (Blueprint $table) {
            $table->id();
            $table->foreignId('client_id')->constrained()->cascadeOnDelete();
            $table->string('type', 32);                 // domain|hosting|server_management|maintenance|seo|other
            $table->string('name');                     // nama layanan
            $table->string('reference')->nullable();    // domain/server terkait
            $table->date('start_date')->nullable();
            $table->date('end_date')->nullable();
            $table->unsignedBigInteger('price')->default(0); // IDR, integer
            $table->string('cycle', 16)->default('yearly');  // monthly|yearly|one_time
            $table->string('status', 16)->default('active');  // active|inactive
            $table->boolean('reminder_enabled')->default(true);
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index('end_date');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('services');
    }
};
