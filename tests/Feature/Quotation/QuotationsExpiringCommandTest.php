<?php

namespace Tests\Feature\Quotation;

use App\Domains\Quotation\Enums\QuotationStatus;
use App\Domains\Quotation\Jobs\SendQuotationEmailJob;
use App\Domains\Quotation\Jobs\SendQuotationWhatsappJob;
use Database\Factories\ClientFactory;
use Database\Factories\QuotationFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Q-2: pengingat kedaluwarsa penawaran H-3 (cermin pola
 * SendOverdueRemindersCommandTest F2-6). Cakupan target, idempoten
 * satu-kali-kirim, dan tanpa perubahan status.
 */
class QuotationsExpiringCommandTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Fonnte aktif supaya kanal WhatsApp deemed deliverable.
        config(['crm.fonnte.enabled' => true, 'crm.fonnte.token' => 'test-token']);
    }

    /**
     * Penawaran yang valid_until tepat $daysFromNow hari lagi.
     * Default: status terkirim, klien punya email + WhatsApp.
     */
    private function quotationExpiringIn(
        int $daysFromNow,
        QuotationStatus $status = QuotationStatus::Sent,
        array $clientOverrides = [],
        array $quotationOverrides = [],
    ): \App\Domains\Quotation\Models\Quotation {
        $client = ClientFactory::new()->create(array_merge([
            'email' => 'klien@example.com',
            'whatsapp' => '08123456789',
        ], $clientOverrides));

        return QuotationFactory::new()->withItems(750000)->create(array_merge([
            'client_id' => $client->id,
            'status' => $status,
            'valid_until' => now()->addDays($daysFromNow)->toDateString(),
        ], $quotationOverrides));
    }

    // ---------- H-3 terkirim sekali, idempoten ----------

    public function test_h3_reminder_queues_once_and_is_not_duplicated_on_second_run(): void
    {
        Queue::fake();

        $quotation = $this->quotationExpiringIn(3);

        $this->artisan('crm:quotations-expiring')
            ->expectsOutputToContain('Pengingat kedaluwarsa penawaran dikirim: 1 penawaran.')
            ->assertSuccessful();

        Queue::assertPushed(SendQuotationEmailJob::class, 1);
        Queue::assertPushed(SendQuotationWhatsappJob::class, 1);
        Queue::assertPushed(
            SendQuotationEmailJob::class,
            fn (SendQuotationEmailJob $job) => $job->quotation->is($quotation)
        );

        $this->assertNotNull($quotation->fresh()->expiry_reminder_sent_at);
        $this->assertDatabaseHas('activity_logs', [
            'subject_type' => $quotation->getMorphClass(),
            'subject_id' => $quotation->id,
            'event' => 'expiry_reminder_sent',
        ]);

        // Status TIDAK diubah oleh pengingat.
        $this->assertSame(QuotationStatus::Sent, $quotation->fresh()->status);

        // Run kedua hari sama: tidak ada antrean ganda.
        $this->artisan('crm:quotations-expiring')
            ->expectsOutputToContain('Tidak ada pengingat kedaluwarsa penawaran yang dikirim.')
            ->assertSuccessful();

        Queue::assertPushed(SendQuotationEmailJob::class, 1);
        Queue::assertPushed(SendQuotationWhatsappJob::class, 1);
    }

    // ---------- hanya H-3 yang dikirim ----------

    public function test_quotation_expiring_on_other_days_is_skipped(): void
    {
        Queue::fake();

        $h2 = $this->quotationExpiringIn(2);
        $h4 = $this->quotationExpiringIn(4);
        $today = $this->quotationExpiringIn(0);
        $overdue = $this->quotationExpiringIn(-1);

        $this->artisan('crm:quotations-expiring')
            ->expectsOutputToContain('Tidak ada pengingat kedaluwarsa penawaran yang dikirim.')
            ->assertSuccessful();

        Queue::assertNothingPushed();

        foreach ([$h2, $h4, $today, $overdue] as $quotation) {
            $this->assertNull($quotation->fresh()->expiry_reminder_sent_at);
        }
    }

    // ---------- hanya status sent yang dikirim ----------

    public function test_only_sent_quotations_receive_reminder(): void
    {
        Queue::fake();

        $draft = $this->quotationExpiringIn(3, QuotationStatus::Draft);
        $accepted = $this->quotationExpiringIn(3, QuotationStatus::Accepted);
        $rejected = $this->quotationExpiringIn(3, QuotationStatus::Rejected);
        $expired = $this->quotationExpiringIn(3, QuotationStatus::Expired);
        $valid = $this->quotationExpiringIn(3); // sent: satu-satunya target

        $this->artisan('crm:quotations-expiring')
            ->expectsOutputToContain('Pengingat kedaluwarsa penawaran dikirim: 1 penawaran.')
            ->assertSuccessful();

        Queue::assertPushed(SendQuotationEmailJob::class, 1);
        Queue::assertPushed(SendQuotationWhatsappJob::class, 1);
        Queue::assertPushed(
            SendQuotationEmailJob::class,
            fn (SendQuotationEmailJob $job) => $job->quotation->is($valid)
        );

        foreach ([$draft, $accepted, $rejected, $expired] as $quotation) {
            $this->assertNull($quotation->fresh()->expiry_reminder_sent_at);
        }
    }

    // ---------- reminder yang sudah ditandai dilewati ----------

    public function test_quotation_already_reminded_is_skipped(): void
    {
        Queue::fake();

        $this->quotationExpiringIn(3, QuotationStatus::Sent, [], [
            'expiry_reminder_sent_at' => now()->subHours(2),
        ]);

        $this->artisan('crm:quotations-expiring')
            ->expectsOutputToContain('Tidak ada pengingat kedaluwarsa penawaran yang dikirim.')
            ->assertSuccessful();

        Queue::assertNothingPushed();
    }

    // ---------- tautan publik untuk pengingat ----------

    public function test_reminder_generates_public_token_before_queueing(): void
    {
        Queue::fake();

        // Penawaran sent tanpa token (mis. diformat manual di DB).
        $quotation = $this->quotationExpiringIn(3, QuotationStatus::Sent, [], ['public_token' => null]);

        $this->artisan('crm:quotations-expiring')->assertSuccessful();

        $quotation->refresh();
        $this->assertNotNull($quotation->public_token);
        $this->assertSame(64, strlen($quotation->public_token));

        // Job yang diantrikan memakai penawaran yang sudah punya tautan.
        Queue::assertPushed(
            SendQuotationEmailJob::class,
            fn (SendQuotationEmailJob $job) => $job->quotation->public_token === $quotation->public_token
        );
    }

    // ---------- batch banyak ----------

    public function test_multiple_h3_quotations_all_queued(): void
    {
        Queue::fake();

        $this->quotationExpiringIn(3);
        $this->quotationExpiringIn(3);
        $this->quotationExpiringIn(3);

        $this->artisan('crm:quotations-expiring')
            ->expectsOutputToContain('Pengingat kedaluwarsa penawaran dikirim: 3 penawaran.')
            ->assertSuccessful();

        Queue::assertPushed(SendQuotationEmailJob::class, 3);
        Queue::assertPushed(SendQuotationWhatsappJob::class, 3);
    }
}
