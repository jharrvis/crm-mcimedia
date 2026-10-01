<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * F2-6: catatan pengingat invoice jatuh tempo yang benar-benar terkirim
     * (satu baris per invoice + kind + channel). Tabel BARU — migrasi F2-1
     * tidak diubah.
     */
    public function up(): void
    {
        Schema::create('invoice_reminders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('invoice_id')->constrained()->cascadeOnDelete();
            $table->string('kind', 8);      // H+1 | H+7 | H+14
            $table->string('channel', 16);  // email | whatsapp
            $table->timestamp('sent_at');
            $table->timestamps();

            // Idempotensi: satu pengingat per (invoice, kind, channel).
            $table->unique(['invoice_id', 'kind', 'channel']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('invoice_reminders');
    }
};
