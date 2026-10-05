<?php

namespace App\Domains\Services\Mail;

use App\Domains\Services\Enums\ServiceReminderKind;
use App\Domains\Services\Models\Service;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Email reminder perpanjangan layanan — pasangan WhatsApp
 * `ServiceReminderDelivery::whatsappMessage` untuk tombol kirim manual di
 * halaman Pengingat (t_6f78ca1d). Isi: nama layanan, jenis, tanggal berakhir,
 * sisa/terlambat hari, dan harga perpanjangan.
 */
class ServiceReminderMailable extends Mailable
{
    use Queueable;
    use SerializesModels;

    public function __construct(public Service $service, public ServiceReminderKind $kind) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: "Pengingat perpanjangan layanan: {$this->service->name} ({$this->kind->label()})",
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.service-reminder',
            with: [
                'service' => $this->service,
                'business' => config('crm.business'),
                'kind' => $this->kind,
                'daysRemaining' => $this->service->daysUntilEnd(),
            ],
        );
    }
}
