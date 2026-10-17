<?php

namespace App\Domains\Invoicing\Jobs;

use App\Domains\Invoicing\Mail\InvoicePaidMailable;
use App\Domains\Invoicing\Models\Invoice;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * Kirim notifikasi invoice LUNAS ke email klien sebagai queued job.
 * Dilewati bila klien tidak punya email.
 */
class InvoicePaidEmailJob implements ShouldQueue
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
            return;
        }

        $email = $invoice->client?->email;

        if (blank($email)) {
            Log::info('Notifikasi lunas via email dilewati: klien tanpa email.', [
                'invoice' => $invoice->number,
            ]);

            return;
        }

        Mail::to($email)->send(new InvoicePaidMailable($invoice));

        Log::info('Notifikasi lunas terkirim via email.', [
            'invoice' => $invoice->number,
            'to' => $email,
        ]);
    }
}
