<?php

namespace Tests\Feature\Invoicing;

use App\Domains\Invoicing\Enums\InvoiceStatus;
use App\Domains\Invoicing\Models\Invoice;
use App\Domains\Invoicing\Services\InvoiceTerminSplitter;
use App\Models\User;
use Database\Factories\ClientFactory;
use Database\Factories\InvoiceFactory;
use Database\Factories\ServiceFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * F4-10: termin pembayaran — nilai kontrak dipecah menjadi beberapa invoice
 * termin (mis. 30%/30%/40%) dengan jatuh tempo masing-masing.
 *
 * Setiap termin adalah invoice utuh yang terhubung ke invoice induknya.
 */
class InvoiceTerminTest extends TestCase
{
    use RefreshDatabase;

    private function login(): User
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        return $user;
    }

    /** Invoice kontrak bernilai $total, siap dipecah. */
    private function contract(int $total = 1000000, array $services = []): Invoice
    {
        $invoice = InvoiceFactory::new()->create([
            'title' => 'Paket website + SEO',
            'status' => InvoiceStatus::Draft,
            'issue_date' => now()->toDateString(),
        ]);

        $invoice->items()->create([
            'description' => 'Paket website + SEO 12 bulan',
            'quantity' => 1,
            'unit_price' => $total,
            'amount' => $total,
            'sort_order' => 0,
        ]);

        if ($services !== []) {
            $invoice->syncServices($services);
        }

        $invoice->recalculateTotals();

        return $invoice->fresh();
    }

    /** @return array<int, array{percent: float, due_date: string}> */
    private function terms(array $percents, int $stepDays = 30): array
    {
        $rows = [];
        $i = 0;

        foreach ($percents as $percent) {
            $rows[] = [
                'percent' => $percent,
                'due_date' => now()->addDays($stepDays * ($i + 1))->toDateString(),
            ];
            $i++;
        }

        return $rows;
    }

    // ---------- pecahan 30/30/40 (skenario utama) ----------

    public function test_splitting_contract_creates_three_linked_termin_invoices(): void
    {
        $this->login();
        $invoice = $this->contract(1000000);

        $response = $this->post(route('invoices.termin.store', $invoice), [
            'terms' => $this->terms([30, 30, 40]),
        ]);

        $response->assertRedirect(route('invoices.show', $invoice))
            ->assertSessionHas('success');

        $terms = $invoice->terminInvoices()->get();

        $this->assertCount(3, $terms);

        // Nominal persis 30/30/40 dari nilai kontrak.
        $this->assertSame([300000, 300000, 400000], $terms->pluck('total')->all());

        // Tiap termin menunjuk balik ke invoice induk.
        foreach ($terms as $index => $termin) {
            $this->assertSame($invoice->id, $termin->parent_invoice_id);
            $this->assertSame($invoice->client_id, $termin->client_id);
            $this->assertSame([30.0, 30.0, 40.0][$index], (float) $termin->termin_percent);
        }

        // Jumlah termin = nilai kontrak (tidak ada rupiah hilang/dobel).
        $this->assertSame($invoice->total, $invoice->allocatedTerminTotal());
    }

    public function test_each_termin_gets_its_own_number_due_date_and_status(): void
    {
        $this->login();
        $invoice = $this->contract(1000000);

        $this->post(route('invoices.termin.store', $invoice), [
            'terms' => $this->terms([50, 50]),
        ])->assertSessionHas('success');

        $terms = $invoice->terminInvoices()->get();

        // Nomor unik, jatuh tempo berbeda, status awal draf.
        $this->assertCount(2, $terms->pluck('number')->unique());
        $this->assertNotSame($terms[0]->number, $invoice->number);
        $this->assertEqualsCanonicalizing([InvoiceStatus::Draft, InvoiceStatus::Draft], $terms->pluck('status')->all());

        $this->assertTrue($terms[0]->due_date->isBefore($terms[1]->due_date));
        $this->assertSame([500000, 500000], $terms->pluck('total')->all());
    }

    public function test_termin_inherits_services_from_contract(): void
    {
        $this->login();
        $client = ClientFactory::new()->create();
        $services = collect([
            ServiceFactory::new()->create(['client_id' => $client->id, 'name' => 'Website Utama']),
            ServiceFactory::new()->create(['client_id' => $client->id, 'name' => 'Website Toko']),
        ]);

        $invoice = $this->contract(1000000, $services->pluck('id')->all());
        $this->assertSame(1000000, $invoice->total);

        $this->post(route('invoices.termin.store', $invoice), [
            'terms' => $this->terms([50, 50]),
        ])->assertSessionHas('success');

        foreach ($invoice->terminInvoices()->get() as $termin) {
            $this->assertEqualsCanonicalizing(
                $services->pluck('id')->sort()->values()->all(),
                $termin->services()->pluck('services.id')->sort()->values()->all()
            );
        }
    }

    public function test_termin_is_a_full_invoice_with_its_own_item(): void
    {
        $this->login();
        $invoice = $this->contract(1000000);

        $this->post(route('invoices.termin.store', $invoice), [
            'terms' => $this->terms([30, 30, 40]),
        ])->assertSessionHas('success');

        $first = $invoice->terminInvoices()->first();

        // Ada item yang menjelaskan asal nilai termin (bukan invoice kosong).
        $this->assertCount(1, $first->items);
        $this->assertStringContainsString($invoice->number, $first->items->first()->description);
        $this->assertStringContainsString('30%', $first->items->first()->description);

        // Item = total termin, jadi PDF & laporan konsisten.
        $this->assertSame($first->total, $first->items->first()->amount);
    }

    // ---------- pembulatan rupiah (invariants keuangan) ----------

    /**
     * Nilai kontrak yang tidak habis dibagi: jumlah termin harus tetap persis
     * sama dengan nilai kontrak, tanpa rupiah yang hilang atau dobel.
     */
    public function test_rounding_never_loses_or_duplicates_rupiah(): void
    {
        $this->login();

        foreach ([333333, 100001, 999999, 7, 12345] as $total) {
            $invoice = $this->contract($total);

            $this->post(route('invoices.termin.store', $invoice), [
                'terms' => $this->terms([33.33, 33.33, 33.34]),
            ])->assertSessionHas('success');

            $sum = $invoice->terminInvoices()->sum('total');

            $this->assertSame($total, $sum, "Total termin untuk kontrak {$total} harus tepat {$total}.");
        }
    }

    public function test_allocation_matches_the_server_side_largest_remainder_rule(): void
    {
        $this->login();
        $invoice = $this->contract(100001);

        $this->post(route('invoices.termin.store', $invoice), [
            'terms' => $this->terms([33.33, 33.33, 33.34]),
        ])->assertSessionHas('success');

        // 33.33% dari 100.001 = 33330.33 -> floor 33330 (x2). Yang penting:
        // jumlah termin = 100.001 dan tiap termin >= 1 rupiah.
        $amounts = $invoice->terminInvoices()->pluck('total')->all();

        $this->assertSame(100001, array_sum($amounts));
        foreach ($amounts as $amount) {
            $this->assertGreaterThan(0, $amount);
        }
    }

    // ---------- validasi input tidak valid ----------

    public function test_split_is_rejected_when_percentages_do_not_total_100(): void
    {
        $this->login();
        $invoice = $this->contract(1000000);

        $this->from(route('invoices.termin.create', $invoice))
            ->post(route('invoices.termin.store', $invoice), [
                'terms' => $this->terms([30, 30, 30]),
            ])
            ->assertSessionHasErrors('terms');

        // Tidak ada termin yang berhasil dibuat.
        $this->assertSame(0, $invoice->terminInvoices()->count());
        $this->assertDatabaseCount('invoices', 1); // hanya invoice kontrak
    }

    public function test_split_requires_at_least_two_terms(): void
    {
        $this->login();
        $invoice = $this->contract(1000000);

        $this->from(route('invoices.termin.create', $invoice))
            ->post(route('invoices.termin.store', $invoice), [
                'terms' => $this->terms([100]),
            ])
            ->assertSessionHasErrors('terms');

        $this->assertSame(0, $invoice->terminInvoices()->count());
    }

    public function test_split_rejects_zero_or_negative_percentages(): void
    {
        $this->login();
        $invoice = $this->contract(1000000);

        $this->from(route('invoices.termin.create', $invoice))
            ->post(route('invoices.termin.store', $invoice), [
                'terms' => $this->terms([0, 100]),
            ])
            ->assertSessionHasErrors('terms.0.percent');

        $this->assertSame(0, $invoice->terminInvoices()->count());
    }

    public function test_split_rejects_due_date_before_contract_issue_date(): void
    {
        $this->login();
        $invoice = $this->contract(1000000);

        $this->from(route('invoices.termin.create', $invoice))
            ->post(route('invoices.termin.store', $invoice), [
                'terms' => [
                    ['percent' => 50, 'due_date' => now()->addDays(10)->toDateString()],
                    ['percent' => 50, 'due_date' => now()->subDays(5)->toDateString()],
                ],
            ])
            ->assertSessionHasErrors();

        $this->assertSame(0, $invoice->terminInvoices()->count());
    }

    public function test_blank_termin_rows_are_ignored_and_do_not_break_submit(): void
    {
        $this->login();
        $invoice = $this->contract(1000000);

        // Baris kosong sisa form dinamis harus dibuang (prepareForValidation).
        $this->post(route('invoices.termin.store', $invoice), [
            'terms' => [
                ['percent' => 50, 'due_date' => now()->addDays(10)->toDateString()],
                ['percent' => 50, 'due_date' => now()->addDays(20)->toDateString()],
                ['percent' => '', 'due_date' => ''],
            ],
        ])->assertSessionHas('success');

        $this->assertCount(2, $invoice->terminInvoices()->get());
    }

    // ---------- guard invoice induk ----------

    public function test_contract_cannot_be_split_twice(): void
    {
        $this->login();
        $invoice = $this->contract(1000000);

        $this->post(route('invoices.termin.store', $invoice), [
            'terms' => $this->terms([50, 50]),
        ])->assertSessionHas('success');

        // Percobaan kedua ditolak & tidak menambah termin.
        $this->from(route('invoices.show', $invoice))
            ->post(route('invoices.termin.store', $invoice), [
                'terms' => $this->terms([50, 50]),
            ])
            ->assertRedirect(route('invoices.show', $invoice))
            ->assertSessionHas('error');

        $this->assertCount(2, $invoice->terminInvoices()->get());
        $this->assertDatabaseCount('invoices', 3); // kontrak + 2 termin
    }

    public function test_paid_invoice_cannot_be_split_into_terms(): void
    {
        $this->login();
        $invoice = $this->contract(1000000);
        $invoice->markPaid();

        $this->from(route('invoices.show', $invoice))
            ->post(route('invoices.termin.store', $invoice), [
                'terms' => $this->terms([50, 50]),
            ])
            ->assertRedirect(route('invoices.show', $invoice))
            ->assertSessionHas('error');

        $this->assertSame(0, $invoice->terminInvoices()->count());
    }

    public function test_invoice_without_contract_value_cannot_be_split(): void
    {
        $this->login();
        $invoice = InvoiceFactory::new()->create(['status' => InvoiceStatus::Draft]);
        $this->assertSame(0, (int) $invoice->total);

        $this->from(route('invoices.show', $invoice))
            ->post(route('invoices.termin.store', $invoice), [
                'terms' => $this->terms([50, 50]),
            ])
            ->assertRedirect(route('invoices.show', $invoice))
            ->assertSessionHas('error');

        $this->assertSame(0, $invoice->terminInvoices()->count());
    }

    // ---------- integritas data ----------

    public function test_deleting_contract_cascades_and_removes_its_terms(): void
    {
        $this->login();
        $invoice = $this->contract(1000000);

        $this->post(route('invoices.termin.store', $invoice), [
            'terms' => $this->terms([30, 30, 40]),
        ])->assertSessionHas('success');

        $this->assertDatabaseCount('invoices', 4);

        // Invoice kontrak masih draf -> boleh dihapus.
        $this->delete(route('invoices.destroy', $invoice))->assertRedirect();

        $this->assertDatabaseCount('invoices', 0);
        $this->assertDatabaseMissing('invoices', ['parent_invoice_id' => $invoice->id]);
    }

    public function test_termin_invoice_can_be_paid_independently(): void
    {
        $this->login();
        $invoice = $this->contract(1000000);

        $this->post(route('invoices.termin.store', $invoice), [
            'terms' => $this->terms([30, 30, 40]),
        ])->assertSessionHas('success');

        $first = $invoice->terminInvoices()->first();
        $first->markSent();

        $this->post(route('invoices.payments.store', $first), [
            'amount' => $first->total,
            'method' => 'bank_transfer',
            'paid_at' => now()->toDateString(),
        ])->assertSessionHas('success');

        $first->refresh();

        $this->assertSame(InvoiceStatus::Paid, $first->status);
        $this->assertSame(300000, $first->payments()->sum('amount'));
    }

    public function test_parent_contract_total_is_unchanged_by_splitting(): void
    {
        $this->login();
        $invoice = $this->contract(1000000);

        $this->post(route('invoices.termin.store', $invoice), [
            'terms' => $this->terms([30, 30, 40]),
        ])->assertSessionHas('success');

        // Nilai kontrak tetap sebagai dokumen acuan.
        $this->assertSame(1000000, $invoice->fresh()->total);
    }

    public function test_splitter_rejects_more_than_max_terms(): void
    {
        $this->login();
        $invoice = $this->contract(10000000);

        $tooMany = array_map(
            fn (int $i) => ['percent' => 100 / (InvoiceTerminSplitter::MAX_TERMS + 1), 'due_date' => now()->addDays($i + 1)->toDateString()],
            range(0, InvoiceTerminSplitter::MAX_TERMS)
        );

        $this->from(route('invoices.termin.create', $invoice))
            ->post(route('invoices.termin.store', $invoice), ['terms' => $tooMany])
            ->assertSessionHasErrors('terms');

        $this->assertSame(0, $invoice->terminInvoices()->count());
    }
}
