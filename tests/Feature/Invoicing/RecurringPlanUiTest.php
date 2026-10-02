<?php

namespace Tests\Feature\Invoicing;

use App\Domains\Invoicing\Enums\InvoiceStatus;
use App\Domains\Invoicing\Enums\RecurringCycle;
use App\Domains\Invoicing\Models\Invoice;
use App\Domains\Invoicing\Models\RecurringPlan;
use App\Domains\Invoicing\Services\RecurringInvoiceGenerator;
use Database\Factories\ClientFactory;
use Database\Factories\InvoiceFactory;
use Database\Factories\RecurringPlanFactory;
use Database\Factories\ServiceFactory;
use Database\Factories\UserFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** UI admin paket invoice recurring (F4-11). */
class RecurringPlanUiTest extends TestCase
{
    use RefreshDatabase;

    private function actingAdmin()
    {
        // F4-1 (kolom users.role) belum ada di branch ini — cukup user terautentikasi.
        return $this->actingAs(UserFactory::new()->create());
    }

    /** @return array<string, mixed> */
    private function payload(array $overrides = []): array
    {
        return array_merge([
            'client_id' => ClientFactory::new()->create()->id,
            'title' => 'Paket Hosting',
            'cycle' => 'monthly',
            'next_invoice_date' => '2026-01-01',
            'due_days' => 14,
            'active' => 1,
            'items' => [
                ['description' => 'Hosting', 'quantity' => 1, 'unit_price' => 500000],
            ],
        ], $overrides);
    }

    // ---------- Akses ----------

    public function test_guest_is_redirected_to_login(): void
    {
        $this->get(route('recurring-plans.index'))->assertRedirect(route('login'));
    }

    public function test_index_renders_plan_list(): void
    {
        $this->actingAdmin();

        RecurringPlanFactory::new()->withItems()->create(['title' => 'Paket Bulanan Indoboga']);

        $this->get(route('recurring-plans.index'))
            ->assertOk()
            ->assertSee('Paket Bulanan Indoboga')
            ->assertSee('Bulanan');
    }

    // ---------- Create ----------

    public function test_create_page_renders(): void
    {
        $this->actingAdmin();

        $this->get(route('recurring-plans.create'))
            ->assertOk()
            ->assertSee('Siklus tagihan')
            ->assertSee('Per 3 Bulan')
            ->assertSee('Per 6 Bulan')
            ->assertSee('Tagihan berikutnya');
    }

    public function test_admin_can_create_plan_with_items(): void
    {
        $this->actingAdmin();
        $client = ClientFactory::new()->create();

        $response = $this->post(route('recurring-plans.store'), $this->payload([
            'client_id' => $client->id,
            'items' => [
                ['description' => 'Hosting', 'quantity' => 1, 'unit_price' => 500000],
                ['description' => 'Domain', 'quantity' => 2, 'unit_price' => 150000],
            ],
        ]));

        $plan = RecurringPlan::firstOrFail();
        $response->assertRedirect(route('recurring-plans.show', $plan));

        $this->assertSame('Paket Hosting', $plan->title);
        $this->assertSame($client->id, $plan->client_id);
        $this->assertCount(2, $plan->items);
        // Total dihitung server dari item: 500.000 + (2 x 150.000).
        $this->assertSame(800000, $plan->total);
    }

    public function test_create_accepts_every_cycle(): void
    {
        $this->actingAdmin();

        foreach (RecurringCycle::cases() as $cycle) {
            $this->post(route('recurring-plans.store'), $this->payload([
                'cycle' => $cycle->value,
                'title' => 'Paket '.$cycle->value,
            ]))->assertSessionHasNoErrors();
        }

        $this->assertSame(
            collect(RecurringCycle::cases())->map(fn ($c) => $c->value)->all(),
            RecurringPlan::orderBy('id')->pluck('cycle')->map(fn ($c) => $c->value)->all(),
        );
    }

    public function test_create_rejects_unknown_cycle(): void
    {
        $this->actingAdmin();

        $this->post(route('recurring-plans.store'), $this->payload(['cycle' => 'weekly']))
            ->assertSessionHasErrors('cycle');

        $this->assertSame(0, RecurringPlan::count());
    }

    public function test_create_requires_at_least_one_item(): void
    {
        $this->actingAdmin();

        $this->post(route('recurring-plans.store'), $this->payload(['items' => []]))
            ->assertSessionHasErrors('items');

        $this->assertSame(0, RecurringPlan::count());
    }

    public function test_create_rejects_service_of_another_client(): void
    {
        $this->actingAdmin();
        $mine = ClientFactory::new()->create();
        $foreignService = ServiceFactory::new()->create();

        $this->post(route('recurring-plans.store'), $this->payload([
            'client_id' => $mine->id,
            'service_id' => $foreignService->id,
        ]))->assertSessionHasErrors('service_id');

        $this->assertSame(0, RecurringPlan::count());
    }

    public function test_create_accepts_service_of_same_client(): void
    {
        $this->actingAdmin();
        $client = ClientFactory::new()->create();
        $service = ServiceFactory::new()->create(['client_id' => $client->id]);

        $this->post(route('recurring-plans.store'), $this->payload([
            'client_id' => $client->id,
            'service_id' => $service->id,
        ]))->assertSessionHasNoErrors();

        $this->assertSame($service->id, RecurringPlan::firstOrFail()->service_id);
    }

    // ---------- Show & edit ----------

    public function test_show_page_displays_plan_details_and_items(): void
    {
        $this->actingAdmin();
        $plan = RecurringPlanFactory::new()->withItems(750000, 'Hosting')->create();

        $this->get(route('recurring-plans.show', $plan))
            ->assertOk()
            ->assertSee($plan->title)
            ->assertSee('Hosting')
            ->assertSee('Item per periode');
    }

    public function test_edit_page_preselects_existing_values(): void
    {
        $this->actingAdmin();
        $plan = RecurringPlanFactory::new()
            ->cycle(RecurringCycle::Quarterly)
            ->withItems(250000, 'Domain')
            ->create();

        $this->get(route('recurring-plans.edit', $plan))
            ->assertOk()
            ->assertSee('Domain')
            ->assertSee('Per 3 Bulan');
    }

    public function test_admin_can_update_plan_and_replaces_items(): void
    {
        $this->actingAdmin();
        $plan = RecurringPlanFactory::new()->withItems(100000, 'Lama')->create();

        $this->put(route('recurring-plans.update', $plan), $this->payload([
            'title' => 'Paket Diperbarui',
            'cycle' => 'yearly',
            'items' => [['description' => 'Baru', 'quantity' => 3, 'unit_price' => 100000]],
        ]))->assertSessionHasNoErrors();

        $plan->refresh();
        $this->assertSame('Paket Diperbarui', $plan->title);
        $this->assertSame(RecurringCycle::Yearly, $plan->cycle);
        $this->assertSame(300000, $plan->total);
        $this->assertCount(1, $plan->items);
        $this->assertSame('Baru', $plan->items->first()->description);
    }

    // ---------- Aksi ----------

    public function test_toggle_switches_active_flag(): void
    {
        $this->actingAdmin();
        $plan = RecurringPlanFactory::new()->withItems()->create(['active' => true]);

        $this->patch(route('recurring-plans.toggle', $plan))->assertRedirect();

        $this->assertFalse($plan->fresh()->active);
    }

    public function test_generate_now_creates_invoice_and_redirects_to_it(): void
    {
        $this->actingAdmin();
        $plan = RecurringPlanFactory::new()->withItems()->create();

        $this->post(route('recurring-plans.generate-now', $plan))
            ->assertRedirect(route('invoices.show', Invoice::firstOrFail()));

        $this->assertSame(1, Invoice::count());
    }

    public function test_generate_now_is_idempotent(): void
    {
        $this->actingAdmin();
        $plan = RecurringPlanFactory::new()->withItems()->create();

        $this->post(route('recurring-plans.generate-now', $plan));
        $this->post(route('recurring-plans.generate-now', $plan))->assertSessionHas('error');

        $this->assertSame(1, Invoice::count());
    }

    public function test_generate_now_refuses_future_period(): void
    {
        $this->actingAdmin();
        $plan = RecurringPlanFactory::new()
            ->dueOn(now()->addMonth())
            ->withItems()
            ->create();

        $this->post(route('recurring-plans.generate-now', $plan))
            ->assertSessionHas('error');

        $this->assertSame(0, Invoice::count());
    }

    public function test_destroy_keeps_published_invoices(): void
    {
        $this->actingAdmin();
        $plan = RecurringPlanFactory::new()->withItems()->create();
        $invoice = app(RecurringInvoiceGenerator::class)->generateForPlan($plan);

        $this->delete(route('recurring-plans.destroy', $plan))
            ->assertRedirect(route('recurring-plans.index'));

        $this->assertNull(RecurringPlan::find($plan->id));
        $this->assertNotNull($invoice->fresh());
    }

    // ---------- Badge di halaman invoice ----------

    public function test_invoice_show_displays_recurring_badge(): void
    {
        $this->actingAdmin();
        $plan = RecurringPlanFactory::new()->cycle(RecurringCycle::Quarterly)->withItems()->create();
        $invoice = app(RecurringInvoiceGenerator::class)->generateForPlan($plan);

        $this->get(route('invoices.show', $invoice))
            ->assertOk()
            ->assertSee('Recurring')
            ->assertSee('Per 3 Bulan')
            ->assertSee($plan->title);
    }

    public function test_invoice_show_survives_deleted_plan(): void
    {
        $this->actingAdmin();
        $plan = RecurringPlanFactory::new()->withItems()->create();
        $invoice = app(RecurringInvoiceGenerator::class)->generateForPlan($plan);
        $plan->delete();

        $this->get(route('invoices.show', $invoice))
            ->assertOk()
            ->assertSee('paket sudah dihapus', escape: false);
    }

    public function test_manual_invoice_show_has_no_recurring_badge(): void
    {
        $this->actingAdmin();
        $invoice = InvoiceFactory::new()->create(['status' => InvoiceStatus::Draft]);

        $this->get(route('invoices.show', $invoice))
            ->assertOk()
            ->assertDontSee('Recurring ·');
    }

    // ---------- Filter ----------

    public function test_index_filters_by_cycle_and_status(): void
    {
        $this->actingAdmin();
        RecurringPlanFactory::new()->cycle(RecurringCycle::Yearly)->withItems()->create(['title' => 'Paket Tahunan']);
        RecurringPlanFactory::new()->cycle(RecurringCycle::Monthly)->inactive()->withItems()->create(['title' => 'Paket Bulanan Mati']);

        $this->get(route('recurring-plans.index', ['cycle' => 'yearly']))
            ->assertOk()
            ->assertSee('Paket Tahunan')
            ->assertDontSee('Paket Bulanan Mati');

        $this->get(route('recurring-plans.index', ['status' => 'inactive']))
            ->assertOk()
            ->assertSee('Paket Bulanan Mati')
            ->assertDontSee('Paket Tahunan');
    }

    /**
     * Opsi "Semua siklus" mengirim cycle=all — nilai sentinel, bukan siklus
     * nyata. Filter harus diperlakukan sebagai "tanpa filter", bukan
     * `where cycle = 'all'` yang selalu kosong.
     */
    public function test_index_cycle_all_shows_every_plan(): void
    {
        $this->actingAdmin();
        RecurringPlanFactory::new()->cycle(RecurringCycle::Yearly)->withItems()->create(['title' => 'Paket Tahunan']);
        RecurringPlanFactory::new()->cycle(RecurringCycle::Monthly)->withItems()->create(['title' => 'Paket Bulanan']);

        $this->get(route('recurring-plans.index', ['cycle' => 'all']))
            ->assertOk()
            ->assertSee('Paket Tahunan')
            ->assertSee('Paket Bulanan');
    }

    /** Sama seperti status=all: sentinel berarti tanpa filter. */
    public function test_index_status_all_shows_every_plan(): void
    {
        $this->actingAdmin();
        RecurringPlanFactory::new()->inactive()->withItems()->create(['title' => 'Paket Mati']);
        RecurringPlanFactory::new()->withItems()->create(['title' => 'Paket Hidup']);

        $this->get(route('recurring-plans.index', ['status' => 'all']))
            ->assertOk()
            ->assertSee('Paket Mati')
            ->assertSee('Paket Hidup');
    }

    /** Nilai cycle tak dikenal tidak boleh membuat error atau hasil kosong diam-diam. */
    public function test_index_ignores_unknown_cycle_value(): void
    {
        $this->actingAdmin();
        RecurringPlanFactory::new()->cycle(RecurringCycle::Monthly)->withItems()->create(['title' => 'Paket Bulanan']);

        // cycle=weekly bukan siklus yang dikenal — diperlakukan sebagai
        // "semua" daripada 500 atau daftar kosong.
        $this->get(route('recurring-plans.index', ['cycle' => 'weekly']))
            ->assertOk()
            ->assertSee('Paket Bulanan');
    }
}