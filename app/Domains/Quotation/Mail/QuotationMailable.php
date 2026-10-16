<?php

namespace App\Domains\Quotation\Mail;

use App\Domains\Quotation\Models\Quotation;
use App\Domains\Quotation\Services\QuotationDelivery;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Email penawaran ke klien: ringkasan + tautan halaman publik + lampiran PDF
 * (dokumen resmi yang sama dengan template PDF penawaran Q-1).
 */
class QuotationMailable extends Mailable
{
    use Queueable;
    use SerializesModels;

    public function __construct(public Quotation $quotation) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Penawaran '.$this->quotation->number.' — '.config('crm.business.name'),
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.quotation',
            with: [
                'quotation' => $this->quotation,
                'business' => config('crm.business'),
                'publicUrl' => QuotationDelivery::publicUrl($this->quotation),
            ],
        );
    }

    /** @return array<int, Attachment> */
    public function attachments(): array
    {
        return [
            Attachment::fromData(fn () => $this->renderPdf(), $this->quotation->number.'.pdf')
                ->withMime('application/pdf'),
        ];
    }

    /** Render PDF penawaran (template Q-1) menjadi string biner. */
    public function renderPdf(): string
    {
        $this->quotation->loadMissing(['client', 'items']);

        return Pdf::loadView('quotations.pdf', ['quotation' => $this->quotation])
            ->setPaper('a4', 'portrait')
            ->output();
    }
}
