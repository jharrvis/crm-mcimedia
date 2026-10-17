<?php

namespace App\Domains\Invoicing\Mail;

use App\Domains\Invoicing\Models\Invoice;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Email notifikasi invoice LUNAS ke klien.
 */
class InvoicePaidMailable extends Mailable
{
    use Queueable;
    use SerializesModels;

    public function __construct(public Invoice $invoice) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Pembayaran Diterima — Invoice '.$this->invoice->number,
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.invoice-paid',
            with: [
                'invoice' => $this->invoice,
                'business' => config('crm.business'),
            ],
        );
    }
}
