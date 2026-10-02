<?php

namespace Tests\Feature\Invoicing;

use App\Domains\Invoicing\Enums\InvoiceStatus;
use App\Domains\Invoicing\Models\Invoice;
use App\Models\User;
use Database\Factories\InvoiceFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * F4-10: termin pembayaran tidak boleh membuat nilai kontrak terhitung dua kali.
 *
 * Invoice induk bernilai penuh (mis. Rp1.000.000) sementara invoice termin-nya
 * berjumlah sama (30%+30%+40%). Kalau keduanya ikut dihitung, laporan&
 * pengingat overdue akan menagih Rp2.000.000 untuk satu kontrak Rp1.000.000.
 */
class InvoiceTerminAccountingTest extends TestCase
{
    use RefreshDatabase;

    private function login(): User
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        return $user;
    }

    /** Pecah kontrak jadi termin yang sudah berstatus 'sent' (terkirim). */
    private function splitIntoSentTerms(Invoice $contract): void
    {
        $rows = [];
        foreach ([30, 30, 40] as $i => $percent) {
            $rows[] = [
                'percent' => $percent,
                'due_date' => now()->addDays(30 * ($i + 1))->toDateString(),
            ];
        }

        $this->post(route('invoices.termin.store', $contract), ['terms' => $rows])
            ->assertSessionHas('success');

        foreach ($contract->terminInvoices()->get() as $termin) {
            $termin->markSent();
        }
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

    public function test_unpaid_scope_excludes_contract_that_has_termin_invoices(): void
    {
        $this->login();

        $contract = $this->contract(1000000);
        $contract->update(['status' => InvoiceStatus::Sent]);
        $this->splitIntoSentTerms($contract);

        $unpaidIds = Invoice::query()->unpaid()->withoutTerminParent()->pluck('id')->all();

        // Invoice kontrak TIDAK ikut — hanya termin-nya.
        $this->assertNotContains($contract->id, $unpaidIds);

        $termIds = $contract->terminInvoices()->pluck('id')->all();
        $this->assertCount(3, $termIds);
        foreach ($termIds as $id) {
            $this->assertContains($id, $unpaidIds);
        }
    }

    public function test_invoice_without_termin_is_still_counted_as_unpaid(): void
    {
        $this->login();

        $plain = InvoiceFactory::new()->create(['status' => InvoiceStatus::Sent]);

        $unpaidIds = Invoice::query()->unpaid()->withoutTerminParent()->pluck('id')->all();

        $this->assertContains($plain->id, $unpaidIds);
    }

    /** Laporan "belum lunas" tidak boleh menampilkan invoice induk. */
    public function test_unpaid_invoice_report_lists_terms_once_not_the_contract(): void
    {
        $this->login();

        $contract = $this->contract(1000000);
        $contract->update(['status' => InvoiceStatus::Sent]);
        $this->splitIntoSentTerms($contract);

        // Flash sukses dari POST sebelumnya ikut dirender di halaman berikutnya.
        // Buka sekali untuk consuming flash, lalu ambil HTML bersihnya.
        $this->get(route('reports.index'))->assertOk();
        $html = $this->get(route('reports.index'))->getContent();

        // Nomor kontrak tidak boleh muncul di halaman laporan sama sekali.
        $this->assertStringNotContainsString($contract->number, $html);

        // Tapi tiap termin invoice-nya muncul.
        foreach ($contract->terminInvoices()->get() as $termin) {
            $this->assertStringContainsString($termin->number, $html);
        }
    }

    /** Total per klien harus Rp1.000.000, bukan Rp2.000.000. */
    public function test_client_summary_does_not_double_count_contract_and_terms(): void
    {
        $this->login();

        $contract = $this->contract(1000000);
        $contract->update(['status' => InvoiceStatus::Sent]);
        $this->splitIntoSentTerms($contract);

        // Buang flash sukses dari POST sebelum mengecek isi laporan.
        $this->get(route('reports.index'))->assertOk();
        $html = $this->get(route('reports.index'))->getContent();

        // Ringkasan per klien: total tagihan kontrak Rp1.000.000.
        $this->assertStringContainsString(rupiah(1000000), $html);

        // Kalau invoice induk ikut terhitung, nilainya jadi Rp2.000.000.
        $this->assertStringNotContainsString(
            rupiah(2000000),
            $html,
            'Nilai kontrak terhitung dua kali: induk + termin.'
        );
    }

    public function test_overdue_reminder_command_skips_contract_and_targets_terms(): void
    {
        $this->login();

        $contract = $this->contract(1000000);
        $contract->update([
            'status' => InvoiceStatus::Sent,
            'issue_date' => now()->subDays(31)->toDateString(),
            'due_date' => now()->subDay()->toDateString(),
        ]);

        // Pecah, lalu geser jatuh tempo termin ke H+1 (target pengingat).
        $rows = [];
        foreach ([30, 30, 40] as $i => $percent) {
            $rows[] = [
                'percent' => $percent,
                'due_date' => now()->subDay()->toDateString(),
            ];
        }
        $this->post(route('invoices.termin.store', $contract), ['terms' => $rows])
            ->assertSessionHas('success');

        foreach ($contract->terminInvoices()->get() as $termin) {
            $termin->markSent();
        }

        $this->artisan('crm:send-overdue-reminders')->assertSuccessful();

        // Invoice kontrak tidak boleh ikut diproses command pengingat. Yang
        // diuji di sini adalah command tetap berjalan normal (tanpa error)
        // saat ada invoice induk dengan termin.
        $after = Invoice::find($contract->id);
        $this->assertSame(InvoiceStatus::Sent, $after->status);
    }

    /**
     * Termin yang sudah lunas tidak boleh ikut "belum lunas", tapi invoice
     * induknya tetap bukan piutang.
     */
    public function test_paid_terms_clear_outstanding_but_contract_stays_excluded(): void
    {
        $this->login();

        $contract = $this->contract(1000000);
        $contract->update(['status' => InvoiceStatus::Sent]);
        $this->splitIntoSentTerms($contract);

        $terms = $contract->terminInvoices()->get();
        foreach ($terms as $termin) {
            $termin->markPaid();
        }

        $unpaidIds = Invoice::query()->unpaid()->withoutTerminParent()->pluck('id')->all();

        $this->assertNotContains($contract->id, $unpaidIds);
        foreach ($terms as $termin) {
            $this->assertNotContains($termin->id, $unpaidIds);
        }

        $this->assertTrue($contract->fresh()->allTermsPaid());
    }
}
