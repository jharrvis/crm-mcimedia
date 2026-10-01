<?php

namespace App\Domains\Invoicing\Jobs;

use App\Domains\Invoicing\Mail\InvoiceMailable;
use App\Domains\Invoicing\Models\Invoice;
use App\Domains\Invoicing\Services\InvoiceDelivery;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * Kirim invoice ke email klien sebagai queued job (koneksi database).
 * Tanpa alamat email klien: job dilewati (skip) + peringatan log, tanpa exception
 * dan tanpa mengubah status invoice.
 */
class SendInvoiceEmailJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 3;

    public function __construct(public Invoice $invoice)
    {
        $this->onConnection('database');
    }

    public function handle(): void
    {
        $invoice = $this->invoice->fresh(['client', 'items']);

        if ($invoice === null) {
            Log::warning('Pengiriman email invoice dilewati: invoice tidak ditemukan.');

            return;
        }

        $email = $invoice->client?->email;

        if (blank($email)) {
            Log::warning('Pengiriman email invoice dilewati: klien tidak punya alamat email.', [
                'invoice' => $invoice->number,
            ]);

            return;
        }

        // Tautan bayar publik harus ada sebelum email dikirim (mekanisme F2-4).
        InvoiceDelivery::ensurePublicToken($invoice);

        Mail::to($email)->send(new InvoiceMailable($invoice));

        InvoiceDelivery::markDelivered(
            $invoice,
            InvoiceDelivery::EVENT_EMAIL,
            "Invoice {$invoice->number} dikirim via email ke {$email}."
        );
    }
}
