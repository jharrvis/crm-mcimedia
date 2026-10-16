<?php

namespace App\Domains\Quotation\Jobs;

use App\Domains\Quotation\Mail\QuotationMailable;
use App\Domains\Quotation\Models\Quotation;
use App\Domains\Quotation\Services\QuotationDelivery;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * Kirim penawaran ke email klien sebagai queued job (koneksi database).
 * Tanpa alamat email klien: job dilewati (skip) + peringatan log, tanpa
 * exception dan tanpa mengubah status penawaran. Cermin F2-5 email path.
 */
class SendQuotationEmailJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 3;

    public function __construct(public Quotation $quotation)
    {
        $this->onConnection('database');
    }

    public function handle(): void
    {
        $quotation = $this->quotation->fresh(['client', 'items']);

        if ($quotation === null) {
            Log::warning('Pengiriman email penawaran dilewati: quotation tidak ditemukan.');

            return;
        }

        $email = $quotation->client?->email;

        if (blank($email)) {
            Log::warning('Pengiriman email penawaran dilewati: klien tidak punya alamat email.', [
                'quotation' => $quotation->number,
            ]);

            return;
        }

        // Tautan publik harus ada sebelum email dikirim (mekanisme Q-2/F2-4).
        QuotationDelivery::ensurePublicToken($quotation);

        Mail::to($email)->send(new QuotationMailable($quotation));

        QuotationDelivery::markDelivered(
            $quotation,
            QuotationDelivery::EVENT_EMAIL,
            "Penawaran {$quotation->number} dikirim via email ke {$email}."
        );
    }
}
