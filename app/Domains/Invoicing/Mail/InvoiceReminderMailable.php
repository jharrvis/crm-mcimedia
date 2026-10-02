<?php

namespace App\Domains\Invoicing\Mail;

use App\Domains\Invoicing\Enums\InvoiceReminderKind;
use App\Domains\Invoicing\Models\Invoice;
use App\Domains\Invoicing\Services\InvoiceDelivery;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Email pengingat sopan untuk invoice yang sudah melewati jatuh tempo (F2-6):
 * nomor invoice, total, sudah lewat berapa hari, dan tautan bayar publik.
 */
class InvoiceReminderMailable extends Mailable
{
    use Queueable;
    use SerializesModels;

    public function __construct(public Invoice $invoice, public InvoiceReminderKind $kind) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: "Pengingat pembayaran: Invoice {$this->invoice->number} ({$this->kind->label()})",
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.invoice-reminder',
            with: [
                'invoice' => $this->invoice,
                'business' => config('crm.business'),
                'paymentUrl' => InvoiceDelivery::paymentUrl($this->invoice),
                'kind' => $this->kind,
                'daysOverdue' => $this->kind->days(),
            ],
        );
    }
}
