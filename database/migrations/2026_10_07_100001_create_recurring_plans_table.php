<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * F4-11: invoice recurring — satu paket penagihan berulang per baris.
 *
 * recurring_plans = template/rencana penagihan (klien, siklus, nominal, item,
 * tanggal tagihan berikutnya). Invoice sendiri tetap dibuat oleh
 * `crm:generate-recurring-invoices` dan ditautkan lewat
 * `invoices.recurring_plan_id` + `invoices.period_start` (unik per periode),
 * sehingga generate ulang untuk periode yang sama tidak menggandakan invoice.
 *
 * Migrasi aditif; tidak mengubah tabel/migrasi lama.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('recurring_plans', function (Blueprint $table) {
            $table->id();
            $table->foreignId('client_id')->constrained()->cascadeOnDelete();
            $table->foreignId('service_id')->nullable()->constrained()->nullOnDelete();
            $table->string('title');
            $table->string('cycle', 16)->default('monthly');   // monthly|quarterly|semiannual|yearly
            $table->text('notes')->nullable();
            $table->date('next_invoice_date');                  // awal periode penagihan berikutnya
            $table->unsignedBigInteger('total')->default(0);    // IDR, integer (tanpa PPN)
            $table->boolean('active')->default(true);
            $table->boolean('auto_send')->default(false);      // true = langsung terkirim, false = draf
            $table->unsignedSmallInteger('due_days')->default(14); // jatuh tempo = issue_date + N hari
            $table->timestamp('last_generated_at')->nullable();
            $table->timestamps();

            $table->index(['active', 'next_invoice_date']);
        });

        Schema::create('recurring_plan_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('recurring_plan_id')->constrained()->cascadeOnDelete();
            $table->string('description');
            $table->unsignedInteger('quantity')->default(1);
            $table->unsignedBigInteger('unit_price')->default(0); // IDR, integer
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();

            $table->index('recurring_plan_id');
        });

        Schema::table('invoices', function (Blueprint $table) {
            // nullOnDelete: menghapus paket recurring tidak menghapus invoice
            // yang sudah terbit (dokumen keuangan).
            $table->foreignId('recurring_plan_id')
                ->nullable()
                ->after('client_id')
                ->constrained()
                ->nullOnDelete();
            $table->string('recurring_cycle', 16)->nullable()->after('recurring_plan_id');
            $table->date('period_start')->nullable()->after('recurring_cycle');
            $table->date('period_end')->nullable()->after('period_start');

            // Idempotensi generate: satu invoice per paket per periode. NULL
            // (invoice manual) tetap bisa banyak karena SQL memperlakukan NULL
            // sebagai berbeda.
            $table->unique(['recurring_plan_id', 'period_start'], 'invoices_recurring_period_unique');
        });
    }

    public function down(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            // Index unik dibuat eksplisit, jadi harus dilepas eksplisit —
            // dropColumn tidak menjatuhkannya (dan SQLite gagal bila index
            // masih menunjuk kolom yang dihapus).
            $table->dropUnique('invoices_recurring_period_unique');
            $table->dropForeign(['recurring_plan_id']);
            $table->dropColumn(['recurring_plan_id', 'recurring_cycle', 'period_start', 'period_end']);
        });

        Schema::dropIfExists('recurring_plan_items');
        Schema::dropIfExists('recurring_plans');
    }
};