<?php

namespace Tests\Feature\Reports;

use App\Domains\Invoicing\Enums\InvoiceStatus;
use App\Domains\Invoicing\Models\Invoice;
use App\Domains\Invoicing\Models\Payment;
use App\Models\User;
use Database\Factories\ClientFactory;
use Database\Factories\InvoiceFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ReportTest extends TestCase
{
    use RefreshDatabase;

    private function login(): User
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        return $user;
    }

    /** Invoice dengan nomor & status eksplisit; total ditentukan lewat item. */
    private function invoice(string $number, InvoiceStatus $status, int $total, array $attrs = []): Invoice
    {
        return InvoiceFactory::new()
            ->withItems($total)
            ->create(array_merge([
                'number' => $number,
                'status' => $status,
            ], $attrs));
    }

    public function test_guest_redirected_to_login(): void
    {
        $this->get(route('reports.index'))->assertRedirect(route('login'));
    }

    public function test_page_renders_sections_and_nav(): void
    {
        $this->login();

        $this->get(route('reports.index'))
            ->assertOk()
            ->assertSee('Laporan')
            ->assertSee('Pemasukan per bulan')
            ->assertSee('Invoice belum lunas')
            ->assertSee('Ringkasan per klien');
    }

    public function test_monthly_income_sums_confirmed_payments_and_excludes_others(): void
    {
        $this->login();

        // Dalam jendela 12 bulan, status confirmed.
        Payment::factory()->create(['amount' => 555000, 'status' => 'confirmed', 'paid_at' => now()]);
        Payment::factory()->create(['amount' => 111000, 'status' => 'confirmed', 'paid_at' => now()->subMonths(2)]);

        // Diabaikan: status belum confirmed.
        Payment::factory()->create(['amount' => 999000, 'status' => 'pending', 'paid_at' => now()]);

        // Diabaikan: di luar jendela 12 bulan.
        Payment::factory()->create(['amount' => 777000, 'status' => 'confirmed', 'paid_at' => now()->subMonths(12)]);

        $response = $this->get(route('reports.index'));

        $response->assertOk();
        $response->assertSee('Rp 555.000');
        $response->assertSee('Rp 111.000');
        $response->assertDontSee('Rp 999.000');
        $response->assertDontSee('Rp 777.000');
        // Total 12 bulan menonjolkan bulan berjalan.
        $response->assertSee('bulan ini');
        $response->assertSee('Rp 666.000'); // 555.000 + 111.000
    }

    public function test_unpaid_invoices_ordered_by_oldest_due_date_with_overdue_markers(): void
    {
        $this->login();
        $client = ClientFactory::new()->create(['name' => 'Klien Piutang']);

        $oldest = $this->invoice('INV-TEST-0001', InvoiceStatus::Overdue, 100000, [
            'client_id' => $client->id,
            'due_date' => now()->subDays(30)->toDateString(),
        ]);
        $newer = $this->invoice('INV-TEST-0002', InvoiceStatus::Sent, 200000, [
            'client_id' => $client->id,
            'due_date' => now()->subDays(10)->toDateString(),
        ]);
        $future = $this->invoice('INV-TEST-0003', InvoiceStatus::Sent, 300000, [
            'client_id' => $client->id,
            'due_date' => now()->addDays(5)->toDateString(),
        ]);

        // Lunas & draf tidak masuk daftar belum lunas.
        $this->invoice('INV-TEST-0004', InvoiceStatus::Paid, 400000, ['client_id' => $client->id]);
        $this->invoice('INV-TEST-0005', InvoiceStatus::Draft, 500000, ['client_id' => $client->id]);

        $response = $this->get(route('reports.index'));

        $response->assertOk();
        $response->assertSeeInOrder([$oldest->number, $newer->number, $future->number]);
        $response->assertDontSee('INV-TEST-0004');
        $response->assertDontSee('INV-TEST-0005');
        $response->assertSee('30 hari lewat');
        $response->assertSee('10 hari lewat');
        $response->assertSee('Belum jatuh tempo');
    }

    public function test_client_summary_totals_paid_and_outstanding_ordered_by_outstanding(): void
    {
        $this->login();
        $clientA = ClientFactory::new()->create(['name' => 'Klien Alfa']);
        $clientB = ClientFactory::new()->create(['name' => 'Klien Bravo']);
        $clientC = ClientFactory::new()->create(['name' => 'Klien Cabang']);

        // A: 1.000.000 lunas + 500.000 terkirim -> total 1.500.000, outstanding 500.000
        $this->invoice('INV-TEST-1001', InvoiceStatus::Paid, 1000000, ['client_id' => $clientA->id]);
        $this->invoice('INV-TEST-1002', InvoiceStatus::Sent, 500000, ['client_id' => $clientA->id]);

        // B: 300.000 terlambat -> total 300.000, outstanding 300.000
        $this->invoice('INV-TEST-1003', InvoiceStatus::Overdue, 300000, ['client_id' => $clientB->id]);

        // C: hanya invoice dibatalkan -> tidak muncul.
        $this->invoice('INV-TEST-1004', InvoiceStatus::Cancelled, 250000, ['client_id' => $clientC->id]);

        $response = $this->get(route('reports.index'));

        $response->assertOk();
        $response->assertSee('Klien Alfa');
        $response->assertSee('Klien Bravo');
        $response->assertDontSee('Klien Cabang');
        // Outstanding A (500.000) lebih besar dari B (300.000).
        $response->assertSeeInOrder(['Klien Alfa', 'Klien Bravo']);
        $response->assertSee('Rp 1.500.000'); // total invoice A
        $response->assertSee('Rp 1.000.000'); // lunas A
        $response->assertSee('Rp 500.000');   // outstanding A
        $response->assertSee('Rp 300.000');   // outstanding B
    }

    public function test_empty_report_page_renders_without_error(): void
    {
        $this->login();

        $this->get(route('reports.index'))
            ->assertOk()
            ->assertSee('Belum ada invoice.')
            ->assertSee('Tidak ada invoice yang belum lunas.');
    }
}
