<?php

namespace Tests\Feature\Quotation;

use App\Domains\Quotation\Enums\QuotationStatus;
use App\Domains\Quotation\Models\Quotation;
use Database\Factories\QuotationFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class QuotationsExpireCommandTest extends TestCase
{
    use RefreshDatabase;

    private function sentQuotation(string $validUntil): Quotation
    {
        $quotation = QuotationFactory::new()->withItems(500000)->create([
            'valid_until' => $validUntil,
        ]);
        $quotation->markSent();

        return $quotation->fresh();
    }

    public function test_sent_past_valid_until_becomes_expired(): void
    {
        $expired = $this->sentQuotation(today()->subDay()->toDateString());

        $this->artisan('crm:quotations-expire')
            ->expectsOutputToContain('1 penawaran ditandai kedaluwarsa.')
            ->assertSuccessful();

        $this->assertSame(QuotationStatus::Expired, $expired->fresh()->status);
    }

    public function test_sent_valid_until_today_is_not_expired(): void
    {
        // Masa berlaku habis perbandingan tanggal saja — valid_until = hari ini
        // masih berlaku (sampai akhir hari).
        $stillValid = $this->sentQuotation(today()->toDateString());

        $this->artisan('crm:quotations-expire')
            ->expectsOutputToContain('Tidak ada penawaran yang kedaluwarsa.')
            ->assertSuccessful();

        $this->assertSame(QuotationStatus::Sent, $stillValid->fresh()->status);
    }

    public function test_sent_future_valid_until_is_untouched(): void
    {
        $stillValid = $this->sentQuotation(today()->addDays(5)->toDateString());

        $this->artisan('crm:quotations-expire')->assertSuccessful();

        $this->assertSame(QuotationStatus::Sent, $stillValid->fresh()->status);
    }

    public function test_expired_quotation_is_written_to_activity_log(): void
    {
        $quotation = $this->sentQuotation(today()->subDays(3)->toDateString());

        $this->artisan('crm:quotations-expire')->assertSuccessful();

        $this->assertDatabaseHas('activity_logs', [
            'subject_type' => $quotation->getMorphClass(),
            'subject_id' => $quotation->id,
            'event' => 'expired',
        ]);
    }

    public function test_non_sent_statuses_are_skipped(): void
    {
        $draft = QuotationFactory::new()->withItems(500000)->create([
            'valid_until' => today()->subDay()->toDateString(),
            'status' => QuotationStatus::Draft,
        ]);

        $this->artisan('crm:quotations-expire')
            ->expectsOutputToContain('Tidak ada penawaran yang kedaluwarsa.')
            ->assertSuccessful();

        $this->assertSame(QuotationStatus::Draft, $draft->fresh()->status);
    }
}
