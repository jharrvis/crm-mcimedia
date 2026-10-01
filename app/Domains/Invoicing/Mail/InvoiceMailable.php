<?php

namespace App\Domains\Invoicing\Mail;

use App\Domains\Invoicing\Models\Invoice;
use App\Domains\Invoicing\Services\InvoiceDelivery;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Email invoice ke klien: ringkasan + tautan bayar publik + lampiran PDF
 * (dokumen resmi yang sama dengan template F2-2).
 */
class InvoiceMailable extends Mailable
{
    use Queueable;
    use SerializesModels;

    public function __construct(public Invoice $invoice) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Invoice '.$this->invoice->number.' — '.config('crm.business.name'),
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.invoice',
            with: [
                'invoice' => $this->invoice,
                'business' => config('crm.business'),
                'bank' => config('crm.bank'),
                'paymentUrl' => InvoiceDelivery::paymentUrl($this->invoice),
                'pdfUrl' => InvoiceDelivery::pdfUrl($this->invoice),
            ],
        );
    }

    /** @return array<int, Attachment> */
    public function attachments(): array
    {
        return [
            Attachment::fromData(fn () => $this->renderPdf(), $this->invoice->number.'.pdf')
                ->withMime('application/pdf'),
        ];
    }

    /** Render PDF invoice (template F2-2) menjadi string biner. */
    public function renderPdf(): string
    {
        $this->invoice->loadMissing(['client', 'service', 'items', 'payments']);

        return Pdf::loadView('invoices.pdf', ['invoice' => $this->invoice])
            ->setPaper('a4', 'portrait')
            ->output();
    }
}
