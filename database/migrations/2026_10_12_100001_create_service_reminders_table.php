<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Reminder WA layanan (t_cc560a11): jejak pengingat perpanjangan yang
     * benar-benar terkirim — satu baris per layanan + kind + channel.
     * Dipakai untuk idempotensi command harian (jalankan 2x tidak dobel).
     */
    public function up(): void
    {
        Schema::create('service_reminders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('service_id')->constrained()->cascadeOnDelete();
            $table->string('kind', 16);    // H-7 | H-3 | H-1 | overdue
            $table->string('channel', 16); // whatsapp
            $table->timestamp('sent_at');
            $table->timestamps();

            // Idempotensi: satu pengingat per (layanan, kind, channel).
            $table->unique(['service_id', 'kind', 'channel']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('service_reminders');
    }
};
