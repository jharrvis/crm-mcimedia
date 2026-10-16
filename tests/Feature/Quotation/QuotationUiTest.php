<?php

namespace Tests\Feature\Quotation;

use App\Domains\Quotation\Enums\QuotationStatus;
use App\Models\User;
use Database\Factories\ClientFactory;
use Database\Factories\QuotationFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class QuotationUiTest extends TestCase
{
    use RefreshDatabase;

    private function login(): User
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        return $user;
    }

    private function itemPayload(array $overrides = []): array
    {
        return array_merge([
            'description' => 'Pengembangan Website',
            'quantity' => 1,
            'unit_price' => 2500000,
        ], $overrides);
    }

    private function quotationPayload($client, array $overrides = []): array
    {
        return array_merge([
            'client_id' => $client->id,
            'title' => 'Penawaran Website Company Profile',
            'issue_date' => now()->toDateString(),
            'valid_until' => now()->addDays(30)->toDateString(),
            'discount' => 0,
            'notes' => 'Harga sudah termasuk maintenance 1 bulan.',
            'terms' => 'DP 50% sebelum pengerjaan dimulai.',
            'items' => [
                $this->itemPayload(),
                $this->itemPayload(['description' => 'Maintenance Bulanan', 'quantity' => 3, 'unit_price' => 300000]),
            ],
        ], $overrides);
    }

    // ---------- akses ----------

    public function test_guest_redirected_to_login_from_index(): void
    {
        $this->get(route('quotations.index'))->assertRedirect(route('login'));
    }

    public function test_guest_redirected_to_login_from_pdf(): void
    {
        $quotation = QuotationFactory::new()->withItems()->create();

        $this->get(route('quotations.pdf', $quotation))->assertRedirect(route('login'));
    }

    // ---------- index & filter ----------

    public function test_index_lists_quotations(): void
    {
        $this->login();
        $quotation = QuotationFactory::new()->create();

        $this->get(route('quotations.index'))
            ->assertOk()
            ->assertSee($quotation->number);
    }

    public function test_index_filter_by_status(): void
    {
        $this->login();
        $draft = QuotationFactory::new()->create(['title' => 'DrafUnik']);
        $sent = QuotationFactory::new()->create(['title' => 'TerkirimUnik']);
        $sent->markSent();

        $response = $this->get(route('quotations.index', ['status' => 'sent']));

        $response->assertOk();
        $response->assertSee($sent->number);
        $response->assertDontSee($draft->number);
    }

    // ---------- create & validasi ----------

    public function test_create_page_shows_preview_number(): void
    {
        $this->login();

        $this->get(route('quotations.create'))
            ->assertOk()
            ->assertSee('QUO-'.now()->format('Ym').'-0001');
    }

    public function test_store_creates_quotation_with_items_and_totals(): void
    {
        $this->login();
        $client = ClientFactory::new()->create();

        $response = $this->post(route('quotations.store'), $this->quotationPayload($client));

        $quotation = \App\Domains\Quotation\Models\Quotation::first();
        $this->assertNotNull($quotation);
        $response->assertRedirect(route('quotations.show', $quotation));

        $this->assertSame('QUO-'.now()->format('Ym').'-0001', $quotation->number);
        $this->assertSame(QuotationStatus::Draft, $quotation->status);
        $this->assertCount(2, $quotation->items);
        // 2.500.000 + (3 * 300.000) = 3.400.000, tanpa diskon.
        $this->assertSame(3400000, $quotation->subtotal);
        $this->assertSame(3400000, $quotation->total);
    }

    public function test_store_with_discount_computes_total(): void
    {
        $this->login();
        $client = ClientFactory::new()->create();

        $this->post(route('quotations.store'), $this->quotationPayload($client, ['discount' => 400000]));

        $quotation = \App\Domains\Quotation\Models\Quotation::first();
        $this->assertSame(3400000, $quotation->subtotal);
        $this->assertSame(3000000, $quotation->total);
    }

    public function test_store_confirm_marks_quotation_sent(): void
    {
        $this->login();
        $client = ClientFactory::new()->create();

        $this->post(route('quotations.store'), $this->quotationPayload($client, ['save_action' => 'confirm']));

        $quotation = \App\Domains\Quotation\Models\Quotation::first();
        $this->assertSame(QuotationStatus::Sent, $quotation->status);
        $this->assertNotNull($quotation->sent_at);
    }

    public function test_store_requires_at_least_one_item(): void
    {
        $this->login();
        $client = ClientFactory::new()->create();

        $response = $this->from(route('quotations.create'))->post(
            route('quotations.store'),
            $this->quotationPayload($client, ['items' => []])
        );

        $response->assertRedirect(route('quotations.create'));
        $response->assertSessionHasErrors('items');
        $this->assertDatabaseCount('quotations', 0);
    }

    public function test_store_rejects_zero_quantity(): void
    {
        $this->login();
        $client = ClientFactory::new()->create();

        $response = $this->from(route('quotations.create'))->post(
            route('quotations.store'),
            $this->quotationPayload($client, ['items' => [$this->itemPayload(['quantity' => 0])]])
        );

        $response->assertSessionHasErrors('items.0.quantity');
        $this->assertDatabaseCount('quotations', 0);
    }

    public function test_store_rejects_valid_until_before_issue_date(): void
    {
        $this->login();
        $client = ClientFactory::new()->create();

        $response = $this->from(route('quotations.create'))->post(
            route('quotations.store'),
            $this->quotationPayload($client, [
                'issue_date' => '2026-10-16',
                'valid_until' => '2026-10-15',
            ])
        );

        $response->assertSessionHasErrors('valid_until');
        $this->assertDatabaseCount('quotations', 0);
    }

    // ---------- update & guard ----------

    public function test_terminal_quotation_cannot_be_edited(): void
    {
        $this->login();
        $quotation = QuotationFactory::new()->withItems()->create();
        $quotation->markSent();
        $quotation->markAccepted();

        $response = $this->get(route('quotations.edit', $quotation));

        $response->assertRedirect(route('quotations.show', $quotation));
        $response->assertSessionHas('error');
    }

    public function test_only_draft_quotation_can_be_deleted(): void
    {
        $this->login();
        $quotation = QuotationFactory::new()->withItems()->create();
        $quotation->markSent();

        $response = $this->delete(route('quotations.destroy', $quotation));

        $response->assertRedirect(route('quotations.show', $quotation));
        $this->assertDatabaseCount('quotations', 1);
    }

    public function test_draft_quotation_can_be_deleted_and_items_cascade(): void
    {
        $this->login();
        $quotation = QuotationFactory::new()->withItems()->create();

        $this->delete(route('quotations.destroy', $quotation))->assertRedirect(route('quotations.index'));

        $this->assertDatabaseCount('quotations', 0);
        $this->assertDatabaseCount('quotation_items', 0);
    }

    // ---------- send & PDF ----------

    public function test_send_marks_quotation_sent(): void
    {
        $this->login();
        $quotation = QuotationFactory::new()->withItems()->create();

        $this->patch(route('quotations.send', $quotation));

        $this->assertSame(QuotationStatus::Sent, $quotation->fresh()->status);
        $this->assertNotNull($quotation->fresh()->sent_at);
    }

    public function test_pdf_returns_pdf_response(): void
    {
        $this->login();
        $quotation = QuotationFactory::new()->withItems()->create([
            'discount' => 100000,
            'terms' => 'DP 50% sebelum pengerjaan.',
        ]);

        $response = $this->get(route('quotations.pdf', $quotation));

        $response->assertOk();
        $response->assertHeader('content-type', 'application/pdf');
    }

    public function test_show_page_displays_quotation_details(): void
    {
        $this->login();
        $quotation = QuotationFactory::new()->withItems()->create([
            'title' => 'JudulUnikPenawaran',
        ]);

        $this->get(route('quotations.show', $quotation))
            ->assertOk()
            ->assertSee($quotation->number)
            ->assertSee('JudulUnikPenawaran');
    }
}