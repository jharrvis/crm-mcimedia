<?php

namespace App\Domains\Quotation\Jobs;

use App\Domains\Quotation\Models\Quotation;
use App\Domains\Quotation\Services\QuotationDelivery;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Kirim penawaran ke WhatsApp klien lewat API Fonnte sebagai queued job
 * (koneksi database). Bila Fonnte nonaktif, token kosong, atau nomor WA klien
 * kosong: job dilewati (skip) + peringatan log, tanpa exception dan tanpa
 * mengubah status penawaran. Cermin F2-5 WhatsApp path & driver yang sama.
 */
class SendQuotationWhatsappJob implements ShouldQueue
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
            Log::warning('Pengiriman WhatsApp penawaran dilewati: quotation tidak ditemukan.');

            return;
        }

        $enabled = (bool) config('crm.fonnte.enabled');
        $token = (string) config('crm.fonnte.token');

        if (! $enabled || blank($token)) {
            Log::warning('Pengiriman WhatsApp penawaran dilewati: Fonnte nonaktif atau token kosong.', [
                'quotation' => $quotation->number,
            ]);

            return;
        }

        $target = QuotationDelivery::normalizeWhatsapp($quotation->client?->whatsapp);

        if (blank($target)) {
            Log::warning('Pengiriman WhatsApp penawaran dilewati: klien tidak punya nomor WhatsApp.', [
                'quotation' => $quotation->number,
            ]);

            return;
        }

        // Tautan publik harus ada sebelum dikirim (mekanisme Q-2/F2-4).
        QuotationDelivery::ensurePublicToken($quotation);

        $response = Http::withHeaders(['Authorization' => $token])
            ->acceptJson()
            ->post((string) config('crm.fonnte.endpoint'), [
                'target' => $target,
                'message' => QuotationDelivery::whatsappMessage($quotation),
                'url' => QuotationDelivery::pdfUrl($quotation),
                'filename' => $quotation->number.'.pdf',
            ]);

        if ($response->failed()) {
            Log::warning('Pengiriman WhatsApp penawaran gagal di Fonnte.', [
                'quotation' => $quotation->number,
                'status' => $response->status(),
            ]);

            return;
        }

        QuotationDelivery::markDelivered(
            $quotation,
            QuotationDelivery::EVENT_WHATSAPP,
            "Penawaran {$quotation->number} dikirim via WhatsApp ke {$target}."
        );
    }
}
