<?php

namespace Tests\Feature\Invoicing;

use App\Domains\Invoicing\Enums\InvoiceStatus;
use App\Domains\Invoicing\Exceptions\InvalidInvoiceTransition;
use App\Domains\Invoicing\Models\Invoice;
use App\Domains\Invoicing\Services\InvoiceTerminSplitter;
use App\Models\User;
use Database\Factories\InvoiceFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * F4-10: guard integritas setelah code review.
 *
 * Setiap test di sini menutup celah yang ditemukan review adversarial:
 * penagihan ganda lewat invoice induk, kehilangan data keuangan saat cascade
 * hapus, nesting termin tanpa batas, dan desinkronisasi nilai kontrak.
 */
class InvoiceTerminGuardTest extends TestCase
{
    use RefreshDatabase;

    private function login(): User
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        return $user;
    }

    private function contract(int $total = 1000000): Invoice
    {
        $invoice = InvoiceFactory::new()->create([
            'title' => 'Paket website + SEO',
            'status' => InvoiceStatus::Draft,
            'issue_date' => now()->toDateString(),
            'due_date' => now()->addDays(30)->toDateString(),
        ]);

        $invoice->items()->create([
            'description' => 'Paket website + SEO',
            'quantity' => 1,
            'unit_price' => $total,
            'amount' => $total,
            'sort_order' => 0,
        ]);
        $invoice->recalculateTotals();

        return $invoice->fresh();
    }

    private function split(Invoice $contract, array $percents = [30, 30, 40]): void
    {
        $rows = [];
        $i = 0;
        foreach ($percents as $percent) {
            $rows[] = [
                'percent' => $percent,
                'due_date' => now()->addDays(30 * ($i + 1))->toDateString(),
            ];
            $i++;
        }

        $this->post(route('invoices.termin.store', $contract), ['terms' => $rows])
            ->assertSessionHas('success');
    }

    private function confirmedPayments(): int
    {
        return (int) DB::table('payments')->where('status', 'confirmed')->sum('amount');
    }

    // ---------- C1: invoice induk tidak boleh ditagih setelah dipecah ----------

    public function test_contract_cannot_have_payment_recorded_after_split(): void
    {
        $this->login();
        $contract = $this->contract(1000000);
        $contract->markSent();
        $this->split($contract);

        $this->post(route('invoices.payments.store', $contract), [
            'amount' => $contract->total,
            'method' => 'bank_transfer',
            'paid_at' => now()->toDateString(),
        ])->assertSessionHas('error');

        // Tidak ada pembayaran masuk untuk invoice induk.
        $this->assertSame(0, $this->confirmedPayments());
        $this->assertNotSame(InvoiceStatus::Paid, $contract->fresh()->status);
    }

    public function test_contract_cannot_get_a_public_payment_link_after_split(): void
    {
        $this->login();
        $contract = $this->contract(1000000);
        $contract->markSent();
        $this->split($contract);

        // markSent() sudah membuat token; yang diuji: menGenerate ulang token
        // (mis. untuk dibagikan ulang) setelah dipecah harus ditolak.
        $before = $contract->fresh()->public_token;

        $this->post(route('invoices.payment-link.generate', $contract))
            ->assertSessionHas('error');

        $this->assertSame($before, $contract->fresh()->public_token);
    }

    public function test_public_page_rejects_payment_confirmation_on_contract(): void
    {
        $this->login();
        $contract = $this->contract(1000000);
        $contract->markSent();

        // Tautan dibuat SEBELUM dipecah, lalu dipecah -> tautan tak berlaku.
        $this->post(route('invoices.payment-link.generate', $contract));
        $token = $contract->fresh()->public_token;
        $this->assertNotNull($token);

        $this->split($contract);

        $this->post(route('invoices.public.payments.store', ['token' => $token]), [
            'sender_name' => 'Klien',
            'amount' => $contract->total,
            'paid_at' => now()->toDateString(),
        ])->assertSessionHas('error');

        $this->assertSame(0, $this->confirmedPayments());
        $this->assertSame(0, $contract->payments()->count());
    }

    public function test_contract_mark_paid_is_rejected_at_model_level(): void
    {
        $this->login();
        $contract = $this->contract(1000000);
        $contract->markSent();
        $this->split($contract);

        // Guard ada di model, jadi jalur kode lain (command, job, Tinker)
        // ikut terlindungi — bukan hanya lewat controller.
        $this->expectException(InvalidInvoiceTransition::class);

        $contract->fresh()->markPaid();
    }

    public function test_is_collectible_is_false_only_for_split_contract(): void
    {
        $this->login();

        $plain = $this->contract(1000000);
        $this->assertTrue($plain->isCollectible());

        $this->split($plain);
        $this->assertFalse($plain->fresh()->isCollectible());

        // Invoice termin sendiri tetap bisa dilunasi.
        $termin = $plain->terminInvoices()->first();
        $this->assertTrue($termin->isCollectible());
    }

    // ---------- C3: hapus induk tidak boleh menghapus termin lunas ----------

    public function test_deleting_contract_is_blocked_when_a_term_is_paid(): void
    {
        $this->login();
        $contract = $this->contract(1000000);
        $this->split($contract, [50, 50]);

        $first = $contract->terminInvoices()->first();
        $first->markSent();
        $this->post(route('invoices.payments.store', $first), [
            'amount' => $first->total,
            'method' => 'bank_transfer',
            'paid_at' => now()->toDateString(),
        ])->assertSessionHas('success');

        $this->assertSame(500000, $this->confirmedPayments());

        // Invoice induk masih draf, tapi tidak boleh boleh dihapus.
        $this->delete(route('invoices.destroy', $contract))
            ->assertRedirect(route('invoices.show', $contract))
            ->assertSessionHas('error');

        // Data keuangan utuh.
        $this->assertDatabaseHas('invoices', ['id' => $contract->id]);
        $this->assertDatabaseHas('invoices', ['id' => $first->id, 'status' => 'paid']);
        $this->assertSame(500000, $this->confirmedPayments());
    }

    public function test_deleting_contract_is_blocked_when_a_term_is_just_sent(): void
    {
        $this->login();
        $contract = $this->contract(1000000);
        $this->split($contract, [50, 50]);

        $contract->terminInvoices()->first()->markSent();

        $this->delete(route('invoices.destroy', $contract))
            ->assertSessionHas('error');

        $this->assertDatabaseCount('invoices', 3);
    }

    public function test_deleting_contract_still_allowed_when_all_terms_are_draft(): void
    {
        $this->login();
        $contract = $this->contract(1000000);
        $this->split($contract);

        $this->assertTrue($contract->fresh()->canBeDeletedSafely());

        // Semua termin masih draf -> cascade aman.
        $this->delete(route('invoices.destroy', $contract))->assertRedirect();

        $this->assertDatabaseCount('invoices', 0);
    }

    // ---------- M2: tidak ada nesting termin ----------

    public function test_a_termin_invoice_cannot_be_split_again(): void
    {
        $this->login();
        $contract = $this->contract(1000000);
        $this->split($contract);

        $termin = $contract->terminInvoices()->first();

        $this->assertFalse($termin->canSplitIntoTerms());

        $this->from(route('invoices.show', $termin))
            ->post(route('invoices.termin.store', $termin), [
                'terms' => [
                    ['percent' => 50, 'due_date' => now()->addDays(30)->toDateString()],
                    ['percent' => 50, 'due_date' => now()->addDays(60)->toDateString()],
                ],
            ])
            ->assertRedirect(route('invoices.show', $termin))
            ->assertSessionHas('error');

        // Tidak ada tingkat ketiga.
        $this->assertDatabaseMissing('invoices', ['parent_invoice_id' => $termin->id]);
    }

    // ---------- M3: nilai kontrak tidak boleh desinkron dari termin ----------

    public function test_contract_cannot_be_edited_after_a_term_was_sent(): void
    {
        $this->login();
        $contract = $this->contract(1000000);
        $this->split($contract);

        $contract->terminInvoices()->first()->markSent();

        $this->get(route('invoices.edit', $contract))
            ->assertRedirect(route('invoices.show', $contract))
            ->assertSessionHas('error');
    }

    public function test_contract_update_is_blocked_after_a_term_was_sent(): void
    {
        $this->login();
        $contract = $this->contract(1000000);
        $this->split($contract);

        $contract->terminInvoices()->first()->markSent();
        $before = $contract->fresh()->total;

        $this->put(route('invoices.update', $contract), [
            'client_id' => $contract->client_id,
            'title' => 'Diubah',
            'issue_date' => now()->toDateString(),
            'due_date' => now()->addDays(10)->toDateString(),
            'items' => [
                ['description' => 'Baru', 'quantity' => 1, 'unit_price' => 9000000],
            ],
        ])->assertRedirect(route('invoices.show', $contract))->assertSessionHas('error');

        // Nilai kontrak tidak berubah.
        $this->assertSame($before, $contract->fresh()->total);
        $this->assertSame(1000000, $contract->fresh()->allocatedTerminTotal());
    }

    public function test_contract_can_still_be_edited_while_all_terms_are_draft(): void
    {
        $this->login();
        $contract = $this->contract(1000000);
        $this->split($contract);

        // Semua termin masih draf -> nilai kontrak masih boleh diselaraskan.
        $this->assertFalse($contract->fresh()->hasNonDraftTerms());

        $this->get(route('invoices.edit', $contract))->assertOk();
    }

    // ---------- M1: batas atas nilai kontrak ----------

    public function test_contract_above_split_ceiling_is_rejected(): void
    {
        $this->login();
        $contract = $this->contract(InvoiceTerminSplitter::MAX_CONTRACT_TOTAL + 1);

        $this->from(route('invoices.show', $contract))
            ->post(route('invoices.termin.store', $contract), [
                'terms' => [
                    ['percent' => 50, 'due_date' => now()->addDays(30)->toDateString()],
                    ['percent' => 50, 'due_date' => now()->addDays(60)->toDateString()],
                ],
            ])
            ->assertSessionHas('error');

        $this->assertSame(0, $contract->terminInvoices()->count());
    }

    public function test_allocation_stays_exact_at_the_ceiling(): void
    {
        $this->login();
        $total = InvoiceTerminSplitter::MAX_CONTRACT_TOTAL;
        $contract = $this->contract($total);

        $this->split($contract);

        $this->assertSame($total, $contract->fresh()->allocatedTerminTotal());
    }
}
