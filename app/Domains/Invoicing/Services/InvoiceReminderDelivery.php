<?php

namespace App\Domains\Invoicing\Services;

use App\Domains\Invoicing\Enums\InvoiceReminderKind;
use App\Domains\Invoicing\Mail\InvoiceReminderMailable;
use App\Domains\Invoicing\Models\Invoice;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * Pengiriman pengingat invoice jatuh tempo (F2-6) memakai jalur F2-5:
 * helper `InvoiceDelivery` (nomor WA 62..., pembuatan public_token, tautan bayar)
 * dan konfigurasi Fonnte yang sama.
 *
 * Berbeda dengan F2-5, pengingat TIDAK mengubah status invoice (tidak memanggil
 * `markDelivered`); status tetap urusan alur/transisi yang sudah ada.
 * Bila tujuan/kredensial kosong, pengiriman dilewati (skip) + peringatan log
 * tanpa exception, dan pemanggil tidak mencatat baris reminder.
 */
class InvoiceReminderDelivery
{
    public const CHANNEL_EMAIL = 'email';

    public const CHANNEL_WHATSAPP = 'whatsapp';

    /** @return array<int, string> */
    public static function channels(): array
    {
        return [self::CHANNEL_EMAIL, self::CHANNEL_WHATSAPP];
    }

    /**
     * Kirim pengingat via email ke alamat klien. True bila benar-benar terkirim;
     * false bila dilewati (tanpa email) — tanpa exception dan tanpa ubah status.
     */
    public function sendEmail(Invoice $invoice, InvoiceReminderKind $kind): bool
    {
        $email = $invoice->client?->email;

        if (blank($email)) {
            Log::warning('Pengingat email invoice dilewati: klien tidak punya alamat email.', [
                'invoice' => $invoice->number,
                'kind' => $kind->value,
            ]);

            return false;
        }

        // Tautan bayar publik harus ada sebelum pengingat dikirim (mekanisme F2-4).
        InvoiceDelivery::ensurePublicToken($invoice);

        Mail::to($email)->send(new InvoiceReminderMailable($invoice, $kind));

        return true;
    }

    /**
     * Kirim pengingat via WhatsApp (Fonnte). True bila benar-benar terkirim;
     * false bila dilewati/gagal — tanpa exception dan tanpa ubah status.
     */
    public function sendWhatsapp(Invoice $invoice, InvoiceReminderKind $kind): bool
    {
        $enabled = (bool) config('crm.fonnte.enabled');
        $token = (string) config('crm.fonnte.token');

        if (! $enabled || blank($token)) {
            Log::warning('Pengingat WhatsApp invoice dilewati: Fonnte nonaktif atau token kosong.', [
                'invoice' => $invoice->number,
                'kind' => $kind->value,
            ]);

            return false;
        }

        $target = InvoiceDelivery::normalizeWhatsapp($invoice->client?->whatsapp);

        if (blank($target)) {
            Log::warning('Pengingat WhatsApp invoice dilewati: klien tidak punya nomor WhatsApp.', [
                'invoice' => $invoice->number,
                'kind' => $kind->value,
            ]);

            return false;
        }

        InvoiceDelivery::ensurePublicToken($invoice);

        $response = Http::withHeaders(['Authorization' => $token])
            ->acceptJson()
            ->post((string) config('crm.fonnte.endpoint'), [
                'target' => $target,
                'message' => self::whatsappMessage($invoice, $kind),
                'url' => InvoiceDelivery::pdfUrl($invoice),
                'filename' => $invoice->number.'.pdf',
            ]);

        if ($response->failed()) {
            Log::warning('Pengingat WhatsApp invoice gagal di Fonnte.', [
                'invoice' => $invoice->number,
                'kind' => $kind->value,
                'status' => $response->status(),
            ]);

            return false;
        }

        return true;
    }

    /** Pesan WhatsApp pengingat: nomor invoice, total, keterlambatan, tautan bayar. */
    public static function whatsappMessage(Invoice $invoice, InvoiceReminderKind $kind): string
    {
        $business = config('crm.business.name');
        $lines = [
            "Halo {$invoice->client?->contact_name},",
            '',
            "Kami ingin mengingatkan invoice dari {$business} yang telah melewati jatuh tempo:",
            "No. Invoice: {$invoice->number}",
            'Total: '.rupiah($invoice->total),
            'Jatuh tempo: '.tgl_id($invoice->due_date)." (lewat {$kind->days()} hari)",
        ];

        $payUrl = InvoiceDelivery::paymentUrl($invoice);
        if ($payUrl) {
            $lines[] = '';
            $lines[] = 'Lihat detail & konfirmasi pembayaran:';
            $lines[] = $payUrl;
        }

        $lines[] = '';
        $lines[] = 'Mohon abaikan pesan ini bila pembayaran sudah dilakukan. Terima kasih.';

        return implode("\n", $lines);
    }
}
