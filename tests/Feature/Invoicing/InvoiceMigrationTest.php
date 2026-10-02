<?php

namespace Tests\Feature\Invoicing;

use App\Domains\Invoicing\Models\Invoice;
use Database\Factories\ClientFactory;
use Database\Factories\InvoiceFactory;
use Database\Factories\ServiceFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * F4-8: migrasi invoice gabungan harus mempertahankan data lama.
 *
 * RefreshDatabase menjalankan seluruh migrasi (termasuk pivot F4-8), jadi tes
 * ini exercising arah sebaliknya lebih dulu: jalankan down() untuk mengembalikan
 * ke skema lama (kolom invoices.service_id), isi data gaya lama, lalu jalankan
 * up() — persis jalur upgrade produksi dari invoice.service_id ke invoice_service.
 */
class InvoiceMigrationTest extends TestCase
{
    use RefreshDatabase;

    private const MIGRATION = __DIR__.'/../../../database/migrations/2026_10_06_100001_create_invoice_service_table.php';

    private function migration(): object
    {
        return require self::MIGRATION;
    }

    /** Kembalikan skema ke kondisi sebelum F4-8 (kolom service_id kembali). */
    private function rollbackToLegacySchema(): void
    {
        $this->migration()->down();

        $this->assertTrue(Schema::hasColumn('invoices', 'service_id'));
        $this->assertFalse(Schema::hasTable('invoice_service'));
    }

    /** Jalankan F4-8 pada skema lama yang sudah berisi data. */
    private function migrateToCombinedSchema(): void
    {
        $this->migration()->up();

        $this->assertFalse(Schema::hasColumn('invoices', 'service_id'));
        $this->assertTrue(Schema::hasTable('invoice_service'));
    }

    private function seedLegacyInvoice(int $clientId, ?int $serviceId, string $number): int
    {
        return DB::table('invoices')->insertGetId([
            'client_id' => $clientId,
            'service_id' => $serviceId,
            'number' => $number,
            'issue_date' => '2026-10-01',
            'due_date' => '2026-10-15',
            'status' => 'draft',
            'subtotal' => 0,
            'total' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_up_migrates_existing_service_id_into_pivot(): void
    {
        $this->rollbackToLegacySchema();

        $clientId = DB::table('clients')->insertGetId([
            'name' => 'Indoboga',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $serviceIds = collect(['Website Utama', 'Website Toko'])->map(fn ($name) => DB::table('services')->insertGetId([
            'client_id' => $clientId,
            'type' => 'hosting',
            'name' => $name,
            'start_date' => '2026-01-01',
            'price' => 300000,
            'cycle' => 'monthly',
            'status' => 'active',
            'reminder_enabled' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]));

        // Satu invoice lama ber-layanan, satu tanpa layanan.
        $withService = $this->seedLegacyInvoice($clientId, $serviceIds[0], 'INV-202610-0001');
        $withoutService = $this->seedLegacyInvoice($clientId, null, 'INV-202610-0002');

        $this->migrateToCombinedSchema();

        // Data invoice tidak hilang.
        $this->assertDatabaseCount('invoices', 2);

        // service_id lama berpindah ke pivot.
        $this->assertDatabaseHas('invoice_service', [
            'invoice_id' => $withService,
            'service_id' => $serviceIds[0],
        ]);
        $this->assertDatabaseMissing('invoice_service', ['invoice_id' => $withoutService]);

        // Relasi many-to-many bisa dibaca Eloquent.
        $invoice = Invoice::with('services')->findOrFail($withService);
        $this->assertSame([$serviceIds[0]], $invoice->services->pluck('id')->all());

        $empty = Invoice::with('services')->findOrFail($withoutService);
        $this->assertCount(0, $empty->services);
    }

    public function test_up_is_safe_when_no_existing_invoices(): void
    {
        $this->rollbackToLegacySchema();

        $this->migrateToCombinedSchema();

        $this->assertDatabaseCount('invoices', 0);
        $this->assertDatabaseCount('invoice_service', 0);
    }

    public function test_down_restores_service_id_column_and_drops_pivot(): void
    {
        $client = ClientFactory::new()->create();
        $service = ServiceFactory::new()->create(['client_id' => $client->id]);

        $invoice = InvoiceFactory::new()->forService($service)->create([
            'client_id' => $client->id,
        ]);
        $this->assertDatabaseHas('invoice_service', [
            'invoice_id' => $invoice->id,
            'service_id' => $service->id,
        ]);

        $this->migration()->down();

        $this->assertTrue(Schema::hasColumn('invoices', 'service_id'));
        $this->assertFalse(Schema::hasTable('invoice_service'));

        // Tautan lama dipulihkan kembali ke invoices.service_id.
        $this->assertDatabaseHas('invoices', [
            'id' => $invoice->id,
            'service_id' => $service->id,
        ]);
    }

    public function test_migration_round_trip_preserves_invoice_count(): void
    {
        $this->rollbackToLegacySchema();
        $this->migrateToCombinedSchema();
        $this->migration()->down();
        $this->migrateToCombinedSchema();

        $this->assertDatabaseCount('invoices', 0);
        $this->assertFalse(Schema::hasColumn('invoices', 'service_id'));
    }
}
