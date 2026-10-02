<?php

namespace Tests\Feature\Invoicing;

use App\Domains\Invoicing\Enums\InvoiceStatus;
use App\Domains\Invoicing\Exceptions\InvalidInvoiceTransition;
use App\Domains\Invoicing\Exceptions\InvalidTerminSplit;
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
        $this->post(route('invoices.termin.store', $contract), [
            'terms' => $this->termRows($percents),
        ])->assertSessionHas('success');
    }

    /** Baris form termin dengan jatuh tempo berurutan 30/60/90 hari. */
    private function termRows(array $percents): array
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

        return $rows;
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

    /**
     * Regression: pagu nilai kontrak harus ditegakkan oleh allocate() itu
     * sendiri, bukan hanya oleh split()/guardParent().
     *
     * allocate() bersifat publik dan jadi pintu masuk semua pembagian nominal.
     * Ketika pagu hanya dipasang di guardParent(), pemanggilan langsung
     * allocate() dengan total di atas batas membuat perkalian basis-point meluap
     * ke float: termin dapat nominal NEGATIF dan jumlah seluruh termin tidak
     * sama dengan nilai kontrak (Rp9.007.199.254.740.992 -> 2 termin positif
     * + 1 termin -Rp86.469.112.845.512).
     */
    public function test_allocate_rejects_totals_above_the_exact_range(): void
    {
        $splitter = new InvoiceTerminSplitter;

        foreach ([
            InvoiceTerminSplitter::MAX_CONTRACT_TOTAL + 1,
            1000000000000,
            9007199254740992, // 2^53, batas presisi float
            PHP_INT_MAX,
        ] as $total) {
            try {
                $splitter->allocate($total, [
                    ['percent' => 30, 'due_date' => now()->addDays(30)->toDateString()],
                    ['percent' => 30, 'due_date' => now()->addDays(60)->toDateString()],
                    ['percent' => 40, 'due_date' => now()->addDays(90)->toDateString()],
                ], now()->toDateString());

                $this->fail("allocate() harus menolak nilai kontrak {$total}.");
            } catch (InvalidTerminSplit $e) {
                $this->assertStringContainsString('melebihi batas', $e->getMessage());
            }
        }
    }

    /** Regression: allocate() tidak boleh menerima nilai kontrak nol/negatif. */
    public function test_allocate_rejects_non_positive_totals(): void
    {
        $splitter = new InvoiceTerminSplitter;

        foreach ([0, -1, -1000000] as $total) {
            try {
                $splitter->allocate($total, [
                    ['percent' => 50, 'due_date' => now()->addDays(30)->toDateString()],
                    ['percent' => 50, 'due_date' => now()->addDays(60)->toDateString()],
                ], now()->toDateString());

                $this->fail("allocate() harus menolak nilai kontrak {$total}.");
            } catch (InvalidTerminSplit $e) {
                $this->assertStringContainsString('lebih dari nol', $e->getMessage());
            }
        }
    }

    /**
     * Invariant allocate() yang dijanjikan di docblock: jumlah nominal termin
     * PERSIS sama dengan nilai kontrak di seluruh rentang yang diterima —
     * bukan cuma lewat HTTP (yang sudah dijaga guardParent()).
     *
     * Termin bernilai NOL belum tentu bug di allocate(): nilai kontrak yang
     * sangat kecil memang bisa membuat satu termin rounds down ke 0, dan itu
     * ditolak di split() oleh guardNonZeroTerms() dengan pesan jelas (lihat
     * test_allocate_may_return_zero_terms_but_split_rejects_them). Yang wajib
     * dijaga di sini: jumlah tetap tepat dan TIDAK ADA nominal negatif —
     * negatif hanya bisa muncul dari overflow basis-point.
     */
    public function test_allocate_sum_always_equals_contract_total(): void
    {
        $splitter = new InvoiceTerminSplitter;
        $issueDate = now()->toDateString();

        $splits = [[30, 30, 40], [50, 50], [33.33, 33.33, 33.34], [1, 99], [12.5, 12.5, 75]];
        $totals = [1, 7, 99999, 100001, 1234567, 999999999, InvoiceTerminSplitter::MAX_CONTRACT_TOTAL];

        foreach ($totals as $total) {
            foreach ($splits as $percents) {
                $rows = [];
                $i = 0;
                foreach ($percents as $percent) {
                    $rows[] = [
                        'percent' => $percent,
                        'due_date' => now()->addDays(30 * ($i + 1))->toDateString(),
                    ];
                    $i++;
                }

                try {
                    $out = $splitter->allocate($total, $rows, $issueDate);
                } catch (InvalidTerminSplit) {
                    // Kontrak terlalu kecil atau di luar rentang eksak: ditolak
                    // secara eksplisit — bukan gagal diam-diam.
                    continue;
                }

                $sum = array_sum(array_column($out, 'amount'));

                $this->assertSame(
                    $total,
                    $sum,
                    "Jumlah termin untuk kontrak {$total} split ".implode('/', $percents)." harus tepat {$total}, bukan {$sum}."
                );

                foreach ($out as $share) {
                    $this->assertGreaterThanOrEqual(
                        0,
                        $share['amount'],
                        'allocate() tidak boleh menghasilkan nominal negatif.'
                    );
                }
            }
        }
    }

    /**
     * Termin bernilai nol harus DITOLAK saat split() (bukan hanya dicegah
     * calculate-nya), supaya tidak ada invoice termin Rp0 yang bisa dikirim ke
     * klien dan membingungkan pencatatan.
     */
    public function test_allocate_may_return_zero_terms_but_split_rejects_them(): void
    {
        $this->login();
        $splitter = new InvoiceTerminSplitter;

        // Rp1 dibagi 30/30/40 -> satu termin bernilai 0.
        $contract = $this->contract(1);

        $this->from(route('invoices.show', $contract))
            ->post(route('invoices.termin.store', $contract), [
                'terms' => $this->termRows([30, 30, 40]),
            ])
            ->assertSessionHas('error');

        // Tidak ada termin yang tersimpan, dan tidak ada termin Rp0.
        $this->assertSame(0, $contract->terminInvoices()->count());
        $this->assertSame(0, Invoice::where('total', '<=', 0)->count());

        // allocate() memang boleh mengembalikan nol — split() yang menolaknya.
        $shares = $splitter->allocate(1, [
            ['percent' => 30, 'due_date' => now()->addDays(30)->toDateString()],
            ['percent' => 30, 'due_date' => now()->addDays(60)->toDateString()],
            ['percent' => 40, 'due_date' => now()->addDays(90)->toDateString()],
        ], now()->toDateString());

        $this->assertContains(0, array_column($shares, 'amount'));
        $this->assertSame(1, array_sum(array_column($shares, 'amount')));
    }
}
