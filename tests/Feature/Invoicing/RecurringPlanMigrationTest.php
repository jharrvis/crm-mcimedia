<?php

namespace Tests\Feature\Invoicing;

use App\Domains\Invoicing\Enums\RecurringCycle;
use App\Domains\Invoicing\Services\RecurringInvoiceGenerator;
use Database\Factories\RecurringPlanFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * F4-11: migrasi recurring_plans harus bisa naik DAN turun (rollback bersih),
 * dan invoice tetap utuh saat paket dihapus.
 */
class RecurringPlanMigrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_tables_and_columns_exist(): void
    {
        $this->assertTrue(Schema::hasTable('recurring_plans'));
        $this->assertTrue(Schema::hasTable('recurring_plan_items'));

        foreach (['client_id', 'service_id', 'title', 'cycle', 'next_invoice_date',
            'total', 'active', 'auto_send', 'due_days', 'last_generated_at'] as $column) {
            $this->assertTrue(
                Schema::hasColumn('recurring_plans', $column),
                "Kolom recurring_plans.{$column} harus ada."
            );
        }

        foreach (['description', 'quantity', 'unit_price', 'sort_order'] as $column) {
            $this->assertTrue(
                Schema::hasColumn('recurring_plan_items', $column),
                "Kolom recurring_plan_items.{$column} harus ada."
            );
        }

        foreach (['recurring_plan_id', 'recurring_cycle', 'period_start', 'period_end'] as $column) {
            $this->assertTrue(
                Schema::hasColumn('invoices', $column),
                "Kolom invoices.{$column} harus ada."
            );
        }
    }

    public function test_unique_period_guard_prevents_duplicate_invoice_for_same_plan(): void
    {
        $plan = RecurringPlanFactory::new()->withItems()->create();

        $first = app(RecurringInvoiceGenerator::class)->generateForPlan($plan);

        // Bypass generator: paksa invoice kedua untuk periode yang sama. Penjaga
        // terakhir di level database harus menolak, walau pengecekan aplikasi
        // somehow terlewat (mis. proses lama sebelum F4-11).
        $this->expectException(\Illuminate\Database\UniqueConstraintViolationException::class);

        $plan->invoices()->create([
            'client_id' => $plan->client_id,
            'number' => 'INV-209912-9999',
            'issue_date' => now(),
            'due_date' => now(),
            'recurring_plan_id' => $plan->id,
            'recurring_cycle' => RecurringCycle::Monthly,
            'period_start' => $first->period_start,
            'period_end' => $first->period_end,
        ]);
    }

    public function test_manual_invoices_are_not_blocked_by_period_guard(): void
    {
        // NULL pada recurring_plan_id/period_start lolos dari unique index
        // (SQL memperlakukan NULL berbeda), jadi invoice manual tetap bisa banyak.
        for ($i = 0; $i < 3; $i++) {
            \App\Domains\Invoicing\Models\Invoice::factory()->create();
        }

        $this->assertSame(3, \App\Domains\Invoicing\Models\Invoice::count());
    }
}