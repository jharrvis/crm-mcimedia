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
 * Kirim notifikasi invoice LUNAS ke WhatsApp klien via Fonnte sebagai queued job.
 * Dilewati bila Fonnte nonaktif atau klien tidak punya nomor WA.
 */
class InvoicePaidWhatsappJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 3;

    public function __construct(public Invoice $invoice)
    {
    }

    public function handle(): void
    {
        $invoice = $this->invoice->fresh(['client']);

        if ($invoice === null) {
            return;
        }

        $enabled = (bool) config('crm.fonnte.enabled');
        $token = (string) config('crm.fonnte.token');

        if (! $enabled || blank($token)) {
            Log::info('Notifikasi lunas via WA dilewati: Fonnte nonaktif.', [
                'invoice' => $invoice->number,
            ]);

            return;
        }

        $target = InvoiceDelivery::normalizeWhatsapp($invoice->client?->whatsapp);

        if (blank($target)) {
            Log::info('Notifikasi lunas via WA dilewati: klien tanpa nomor WA.', [
                'invoice' => $invoice->number,
            ]);

            return;
        }

        $amount = number_format((int) $invoice->total, 0, ',', '.');
        $message = "✅ *Pembayaran Diterima*\n\n"
            . "Invoice {$invoice->number} sebesar Rp {$amount} telah kami terima dan invoice dinyatakan *LUNAS*.\n\n"
            . "Terima kasih atas pembayaran Anda.\n"
            . config('crm.business.name');

        $response = Http::withHeaders(['Authorization' => $token])
            ->acceptJson()
            ->post((string) config('crm.fonnte.endpoint'), [
                'target' => $target,
                'message' => $message,
            ]);

        if ($response->failed()) {
            Log::warning('Notifikasi lunas via WA gagal di Fonnte.', [
                'invoice' => $invoice->number,
                'status' => $response->status(),
            ]);

            return;
        }

        Log::info('Notifikasi lunas terkirim via WA.', [
            'invoice' => $invoice->number,
            'to' => $target,
        ]);
    }
}
