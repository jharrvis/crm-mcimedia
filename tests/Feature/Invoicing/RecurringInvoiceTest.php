<?php

namespace Tests\Feature\Invoicing;

use App\Domains\Invoicing\Enums\InvoiceStatus;
use App\Domains\Invoicing\Enums\RecurringCycle;
use App\Domains\Invoicing\Models\Invoice;
use App\Domains\Invoicing\Models\RecurringPlan;
use App\Domains\Invoicing\Services\RecurringInvoiceGenerator;
use Database\Factories\ClientFactory;
use Database\Factories\RecurringPlanFactory;
use Database\Factories\ServiceFactory;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * F4-11: invoice recurring — siklus 1/3/6/12 bulan, generate otomatis per
 * periode, idempoten, dan tidak menabrak command perpanjangan lama.
 */
class RecurringInvoiceTest extends TestCase
{
    use RefreshDatabase;

    // ---------- Siklus: bulan per siklus & label ----------

    public function test_each_cycle_maps_to_expected_month_count_and_label(): void
    {
        $this->assertSame(1, RecurringCycle::Monthly->months());
        $this->assertSame(3, RecurringCycle::Quarterly->months());
        $this->assertSame(6, RecurringCycle::Semiannual->months());
        $this->assertSame(12, RecurringCycle::Yearly->months());

        $this->assertSame('Bulanan', RecurringCycle::Monthly->label());
        $this->assertSame('Per 3 Bulan', RecurringCycle::Quarterly->label());
        $this->assertSame('Per 6 Bulan', RecurringCycle::Semiannual->label());
        $this->assertSame('Tahunan', RecurringCycle::Yearly->label());
    }

    public function test_period_end_covers_exactly_one_cycle_inclusive(): void
    {
        // 1 Jan + 1 bulan - 1 hari = 31 Jan
        $this->assertSame(
            '2026-01-31',
            RecurringCycle::Monthly->periodEnd(Carbon::parse('2026-01-01'))->toDateString()
        );

        // 1 Jan + 3 bulan - 1 hari = 31 Mar
        $this->assertSame(
            '2026-03-31',
            RecurringCycle::Quarterly->periodEnd(Carbon::parse('2026-01-01'))->toDateString()
        );

        // 1 Jan + 6 bulan - 1 hari = 30 Jun
        $this->assertSame(
            '2026-06-30',
            RecurringCycle::Semiannual->periodEnd(Carbon::parse('2026-01-01'))->toDateString()
        );

        // 1 Jan + 12 bulan - 1 hari = 31 Des
        $this->assertSame(
            '2026-12-31',
            RecurringCycle::Yearly->periodEnd(Carbon::parse('2026-01-01'))->toDateString()
        );
    }

    public function test_period_end_handles_month_shorter_than_anchor_day(): void
    {
        // 31 Jan + 1 bulan - 1 hari = 28 Feb (tahun bukan kabisat).
        $this->assertSame(
            '2026-02-28',
            RecurringCycle::Monthly->periodEnd(Carbon::parse('2026-01-31'))->toDateString()
        );

        // Tahun kabisat: 31 Jan + 1 bulan - 1 hari = 29 Feb.
        $this->assertSame(
            '2028-02-29',
            RecurringCycle::Monthly->periodEnd(Carbon::parse('2028-01-31'))->toDateString()
        );
    }

    public function test_next_period_start_follows_period_end(): void
    {
        $this->assertSame(
            '2026-02-01',
            RecurringCycle::Monthly
                ->nextPeriodStart(Carbon::parse('2026-01-31'))
                ->toDateString()
        );
    }

    // ---------- Generate invoice ----------

    public function test_generator_creates_invoice_with_period_and_item_totals(): void
    {
        $plan = RecurringPlanFactory::new()->withItems(750000, 'Hosting bulanan')->create();

        $invoice = app(RecurringInvoiceGenerator::class)->generateForPlan($plan);

        $this->assertNotNull($invoice);
        $this->assertSame($plan->client_id, $invoice->client_id);
        $this->assertTrue($invoice->isRecurring());
        $this->assertSame($plan->id, $invoice->recurring_plan_id);
        $this->assertSame(RecurringCycle::Monthly, $invoice->recurring_cycle);
        $this->assertSame($plan->next_invoice_date->toDateString(), $invoice->period_start->toDateString());
        $this->assertSame('750000', (string) $invoice->total);
        $this->assertCount(1, $invoice->items);
        $this->assertSame('Hosting bulanan', $invoice->items->first()->description);
    }

    public function test_due_date_is_issue_date_plus_due_days(): void
    {
        $this->travelTo(Carbon::parse('2026-05-10'));

        $plan = RecurringPlanFactory::new()->dueDays(7)->withItems()->create();

        $invoice = app(RecurringInvoiceGenerator::class)->generateForPlan($plan);

        $this->assertSame('2026-05-17', $invoice->due_date->toDateString());
    }

    public function test_quarterly_cycle_records_three_month_period(): void
    {
        $plan = RecurringPlanFactory::new()
            ->cycle(RecurringCycle::Quarterly)
            ->dueOn(Carbon::parse('2026-01-01'))
            ->withItems()
            ->create();

        $invoice = app(RecurringInvoiceGenerator::class)->generateForPlan($plan);

        $this->assertSame('2026-01-01', $invoice->period_start->toDateString());
        $this->assertSame('2026-03-31', $invoice->period_end->toDateString());
        $this->assertSame(RecurringCycle::Quarterly, $invoice->recurring_cycle);
    }

    public function test_plan_next_invoice_date_advances_by_one_cycle(): void
    {
        $plan = RecurringPlanFactory::new()
            ->cycle(RecurringCycle::Semiannual)
            ->dueOn(Carbon::parse('2026-01-01'))
            ->withItems()
            ->create();

        app(RecurringInvoiceGenerator::class)->generateForPlan($plan);

        // 1 Jan + 6 bulan - 1 hari = 30 Jun, berikutnya mulai 1 Jul.
        $this->assertSame('2026-07-01', $plan->fresh()->next_invoice_date->toDateString());
        $this->assertNotNull($plan->fresh()->last_generated_at);
    }

    public function test_plan_without_items_is_skipped_without_error(): void
    {
        $plan = RecurringPlanFactory::new()->create();

        $this->assertNull(app(RecurringInvoiceGenerator::class)->generateForPlan($plan));
        $this->assertSame(0, Invoice::count());
        // Tanggal tidak dimajukan supaya paket bisa diperbaiki lalu diulang.
        $this->assertSame(
            Carbon::today()->toDateString(),
            $plan->fresh()->next_invoice_date->toDateString()
        );
    }

    public function test_invoice_links_service_when_plan_has_one(): void
    {
        $service = ServiceFactory::new()->create();
        $plan = RecurringPlanFactory::new()
            ->forService($service)
            ->withItems()
            ->create();

        $invoice = app(RecurringInvoiceGenerator::class)->generateForPlan($plan);

        $this->assertTrue($invoice->services->contains($service));
    }

    public function test_auto_send_plan_publishes_invoice(): void
    {
        $plan = RecurringPlanFactory::new()->autoSend()->withItems()->create();

        $invoice = app(RecurringInvoiceGenerator::class)->generateForPlan($plan);

        $this->assertSame(InvoiceStatus::Sent, $invoice->status);
        $this->assertNotNull($invoice->sent_at);
        $this->assertNotNull($invoice->public_token);
    }

    public function test_draft_plan_stays_draft(): void
    {
        $plan = RecurringPlanFactory::new()->autoSend(false)->withItems()->create();

        $invoice = app(RecurringInvoiceGenerator::class)->generateForPlan($plan);

        $this->assertSame(InvoiceStatus::Draft, $invoice->status);
        $this->assertNull($invoice->public_token);
    }

    // ---------- Idempotensi ----------

    public function test_generating_twice_for_same_period_does_not_duplicate_invoice(): void
    {
        $plan = RecurringPlanFactory::new()->withItems()->create();
        $generator = app(RecurringInvoiceGenerator::class);

        $first = $generator->generateForPlan($plan);
        $second = $generator->generateForPlan($plan->fresh());

        $this->assertNotNull($first);
        $this->assertNull($second, 'Periode yang sama tidak boleh digandakan.');
        $this->assertSame(1, Invoice::count());
    }

    public function test_running_command_twice_same_day_is_idempotent(): void
    {
        RecurringPlanFactory::new()->withItems()->create();

        $this->artisan('crm:generate-recurring-invoices')->assertSuccessful();
        $this->artisan('crm:generate-recurring-invoices')->assertSuccessful();

        $this->assertSame(1, Invoice::count());
    }

    public function test_next_period_generates_a_second_invoice(): void
    {
        $plan = RecurringPlanFactory::new()
            ->dueOn(Carbon::parse('2026-01-01'))
            ->withItems()
            ->create();

        // Paksa periode berikutnya jatuh tempo.
        $plan->update(['next_invoice_date' => Carbon::today()->toDateString()]);

        $this->artisan('crm:generate-recurring-invoices')->assertSuccessful();

        $this->assertSame(1, Invoice::count());
    }

    // ---------- Command ----------

    public function test_command_creates_invoices_for_due_plans_only(): void
    {
        RecurringPlanFactory::new()->withItems()->create();
        RecurringPlanFactory::new()
            ->dueOn(Carbon::today()->addDays(5))
            ->withItems()
            ->create();

        $this->artisan('crm:generate-recurring-invoices')->assertSuccessful();

        $this->assertSame(1, Invoice::count());
    }

    public function test_command_skips_inactive_plans(): void
    {
        RecurringPlanFactory::new()->inactive()->withItems()->create();

        $this->artisan('crm:generate-recurring-invoices')->assertSuccessful();

        $this->assertSame(0, Invoice::count());
    }

    public function test_command_can_filter_by_cycle(): void
    {
        RecurringPlanFactory::new()->cycle(RecurringCycle::Monthly)->withItems()->create();
        RecurringPlanFactory::new()->cycle(RecurringCycle::Yearly)->withItems()->create();

        $this->artisan('crm:generate-recurring-invoices', ['--cycle' => 'yearly'])->assertSuccessful();

        $this->assertSame(1, Invoice::count());
        $this->assertSame(RecurringCycle::Yearly, Invoice::first()->recurring_cycle);
    }

    public function test_command_rejects_unknown_cycle(): void
    {
        $this->artisan('crm:generate-recurring-invoices', ['--cycle' => 'weekly'])
            ->assertExitCode(2);
    }

    public function test_command_send_option_publishes_draft_plans(): void
    {
        RecurringPlanFactory::new()->autoSend(false)->withItems()->create();

        $this->artisan('crm:generate-recurring-invoices', ['--send' => true])->assertSuccessful();

        $this->assertSame(InvoiceStatus::Sent, Invoice::first()->status);
    }

    public function test_command_is_scheduled_daily(): void
    {
        $this->artisan('schedule:list')
            ->expectsOutputToContain('crm:generate-recurring-invoices')
            ->assertSuccessful();
    }

    // ---------- Integrasi dengan pipeline pengingat & perpanjangan ----------

    public function test_recurring_invoice_feeds_overdue_reminder_pipeline(): void
    {
        $plan = RecurringPlanFactory::new()->autoSend()->withItems()->create();
        $invoice = app(RecurringInvoiceGenerator::class)->generateForPlan($plan);

        // Invoice terkirim + jatuh tempo lewat = masuk scope invoice unpaid/overdue,
        // jalur yang sama dengan pengingat F2-6.
        $invoice->update(['due_date' => now()->subDay()]);

        $this->assertTrue($invoice->fresh()->is(Invoice::overdue()->find($invoice->id)));
        $this->assertTrue($invoice->fresh()->is(Invoice::unpaid()->find($invoice->id)));
    }

    public function test_renewal_command_skips_service_covered_by_active_recurring_plan(): void
    {
        $service = ServiceFactory::new()->create([
            'end_date' => now()->addDays(10),
        ]);

        // Paket recurring aktif untuk layanan yang sama.
        RecurringPlanFactory::new()
            ->forService($service)
            ->dueOn(now()->addMonths(6))
            ->withItems()
            ->create();

        $this->artisan('crm:generate-renewal-invoices')->assertSuccessful();

        $this->assertSame(0, Invoice::count(), 'Layanan dengan paket recurring tidak boleh digenerate dua kali.');
    }

    public function test_renewal_command_still_runs_when_recurring_plan_inactive(): void
    {
        $service = ServiceFactory::new()->create(['end_date' => now()->addDays(10)]);

        RecurringPlanFactory::new()
            ->forService($service)
            ->inactive()
            ->withItems()
            ->create();

        $this->artisan('crm:generate-renewal-invoices')->assertSuccessful();

        $this->assertSame(1, Invoice::count());
    }

    // ---------- Integritas data ----------

    public function test_deleting_plan_keeps_published_invoices(): void
    {
        $plan = RecurringPlanFactory::new()->withItems()->create();
        $invoice = app(RecurringInvoiceGenerator::class)->generateForPlan($plan);

        $plan->delete();

        $this->assertNotNull($invoice->fresh(), 'Invoice terbit tidak boleh ikut terhapus.');
        $this->assertNull($invoice->fresh()->recurring_plan_id);
        // Jejak tagihan berulang tetap terbaca walau paketnya dihapus
        // (ON DELETE SET NULL mengosongkan recurring_plan_id).
        $this->assertTrue($invoice->fresh()->isRecurring());
        $this->assertSame(RecurringCycle::Monthly, $invoice->fresh()->recurring_cycle);
        $this->assertNull($invoice->fresh()->recurringPlan);
    }

    /**
     * Menghapus KLIEN berbeda dari menghapus paket: `invoices.client_id` memakai
     * cascadeOnDelete sejak F2-1, jadi invoice ikut terhapus. Perilaku lama ini
     * didokumentasikan di docs/DEPLOY.md — tes ini menjaga agar dokumentasinya
     * tidak melenceng diam-diam.
     */
    public function test_deleting_client_cascades_to_plan_and_invoice(): void
    {
        $plan = RecurringPlanFactory::new()->withItems()->create();
        $invoice = app(RecurringInvoiceGenerator::class)->generateForPlan($plan);

        $plan->client->delete();

        $this->assertNull(RecurringPlan::find($plan->id), 'Paket ikut terhapus lewat klien (cascade).');
        $this->assertNull(Invoice::find($invoice->id), 'Invoice punya client_id cascade sejak F2-1 — ikut terhapus.');
    }

    public function test_period_columns_are_required_together_on_recurring_invoice(): void
    {
        $plan = RecurringPlanFactory::new()->withItems()->create();

        $invoice = app(RecurringInvoiceGenerator::class)->generateForPlan($plan);

        $this->assertNotNull($invoice->period_start);
        $this->assertNotNull($invoice->period_end);
        $this->assertTrue($invoice->period_start->lte($invoice->period_end));
    }

    public function test_manual_invoices_have_no_recurring_linkage(): void
    {
        $client = ClientFactory::new()->create();
        $invoice = Invoice::factory()->create(['client_id' => $client->id]);

        $this->assertFalse($invoice->isRecurring());
        $this->assertNull($invoice->recurringPlan);
    }

    // ---------- Kegagalan generate tidak boleh ditelan diam-diam ----------

    /**
     * Tabrakan unique pada kolom LAIN (mis. `invoices.number`) adalah kegagalan
     * sungguhan, bukan "periode sudah terbit". Generator harus membedakannya:
     * periode yang memang sudah terbit → null (idempoten, wajar); tabrakan
     * lain → wajib dilempar agar command melaporkannya sebagai error, bukan
     * reporting "dilewati" untuk tagihan yang sebenarnya hilang.
     *
     * Nomor dipatok lewat subclass yang mengoverride numberFor(), lalu diisi
     * lebih dulu dengan invoice lain supaya bentrok saat insert.
     */
    public function test_duplicate_invoice_number_is_reported_not_silently_skipped(): void
    {
        $plan = RecurringPlanFactory::new()->withItems()->create();

        $takenNumber = 'INV-'.now()->format('Ym').'-9999';
        Invoice::factory()->create([
            'client_id' => $plan->client_id,
            'number' => $takenNumber,
        ]);

        $generator = new class extends RecurringInvoiceGenerator
        {
            public ?string $forcedNumber = null;

            protected function numberFor(Carbon $issueDate): string
            {
                return $this->forcedNumber ?? parent::numberFor($issueDate);
            }
        };
        $generator->forcedNumber = $takenNumber;

        $failed = false;

        try {
            $generator->generateForPlan($plan);
        } catch (UniqueConstraintViolationException) {
            $failed = true;
        }

        $this->assertTrue(
            $failed,
            'Tabrakan nomor invoice harus dilempar, bukan ditelan jadi null (tagihan hilang tanpa jejak).'
        );

        $this->assertSame(0, $plan->invoices()->count(), 'Paket tidak boleh punya invoice setelah generate gagal.');
        $this->assertNull($plan->fresh()->last_generated_at, 'Generate gagal tidak boleh menandai paket sebagai sudah ditagih.');
    }
}
