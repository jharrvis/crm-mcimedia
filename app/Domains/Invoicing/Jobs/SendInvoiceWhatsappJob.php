<?php

namespace App\Domains\Invoicing\Jobs;

use App\Domains\Invoicing\Models\Invoice;
use App\Domains\Invoicing\Services\InvoiceDelivery;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Kirim invoice ke WhatsApp klien lewat API Fonnte sebagai queued job
 * (koneksi database). Bila Fonnte nonaktif, token kosong, atau nomor WA klien
 * kosong: job dilewati (skip) + peringatan log, tanpa exception dan tanpa
 * mengubah status invoice.
 */
class SendInvoiceWhatsappJob implements ShouldQueue
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
            Log::warning('Pengiriman WhatsApp invoice dilewati: invoice tidak ditemukan.');

            return;
        }

        $enabled = (bool) config('crm.fonnte.enabled');
        $token = (string) config('crm.fonnte.token');

        if (! $enabled || blank($token)) {
            Log::warning('Pengiriman WhatsApp invoice dilewati: Fonnte nonaktif atau token kosong.', [
                'invoice' => $invoice->number,
            ]);

            return;
        }

        $target = InvoiceDelivery::normalizeWhatsapp($invoice->client?->whatsapp);

        if (blank($target)) {
            Log::warning('Pengiriman WhatsApp invoice dilewati: klien tidak punya nomor WhatsApp.', [
                'invoice' => $invoice->number,
            ]);

            return;
        }

        // Tautan bayar/PDF publik harus ada sebelum dikirim (mekanisme F2-4).
        InvoiceDelivery::ensurePublicToken($invoice);

        $response = Http::withHeaders(['Authorization' => $token])
            ->acceptJson()
            ->post((string) config('crm.fonnte.endpoint'), [
                'target' => $target,
                'message' => InvoiceDelivery::whatsappMessage($invoice),
                'url' => InvoiceDelivery::pdfUrl($invoice),
                'filename' => $invoice->number.'.pdf',
            ]);

        if ($response->failed()) {
            Log::warning('Pengiriman WhatsApp invoice gagal di Fonnte.', [
                'invoice' => $invoice->number,
                'status' => $response->status(),
            ]);

            return;
        }

        InvoiceDelivery::markDelivered(
            $invoice,
            InvoiceDelivery::EVENT_WHATSAPP,
            "Invoice {$invoice->number} dikirim via WhatsApp ke {$target}."
        );
    }
}
