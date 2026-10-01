<?php

namespace App\Domains\Invoicing\Services;

use App\Domains\Core\Models\ActivityLog;
use App\Domains\Invoicing\Models\Invoice;
use Illuminate\Support\Str;

/**
 * Logika bersama pengiriman invoice (F2-5) yang dipakai job email & WhatsApp:
 * normalisasi nomor WA, penyusunan tautan/ringkasan, pembuatan token publik,
 * dan penandaan invoice terkirim setelah pengiriman pertama berhasil.
 */
class InvoiceDelivery
{
    /** Event activity log untuk riwayat pengiriman. */
    public const EVENT_EMAIL = 'email_sent';

    public const EVENT_WHATSAPP = 'whatsapp_sent';

    /** URL halaman pembayaran publik (F2-4) atau null bila tautan belum ada. */
    public static function paymentUrl(Invoice $invoice): ?string
    {
        if (blank($invoice->public_token)) {
            return null;
        }

        return route('invoices.public.show', ['token' => $invoice->public_token]);
    }

    /** URL PDF publik (F2-4) atau null bila tautan belum ada. */
    public static function pdfUrl(Invoice $invoice): ?string
    {
        if (blank($invoice->public_token)) {
            return null;
        }

        return route('invoices.public.pdf', ['token' => $invoice->public_token]);
    }

    /**
     * Pastikan invoice punya public_token sebelum tautan bayar dikirim.
     * Memakai mekanisme F2-4 (Str::random(64)); tidak mengubah status invoice.
     */
    public static function ensurePublicToken(Invoice $invoice): void
    {
        if (blank($invoice->public_token)) {
            $invoice->forceFill(['public_token' => Str::random(64)])->save();
        }
    }

    /**
     * Normalisasi nomor WhatsApp ke format internasional 62...
     * "0812..." / "+62 812..." / "62812..." -> "62812...". Null bila kosong.
     */
    public static function normalizeWhatsapp(?string $number): ?string
    {
        if (blank($number)) {
            return null;
        }

        // Sisakan digit saja.
        $digits = preg_replace('/\D+/', '', $number);
        if (blank($digits)) {
            return null;
        }

        // 0xxx (lokal) atau 8xxx -> awali 62.
        if (str_starts_with($digits, '0')) {
            $digits = '62'.substr($digits, 1);
        } elseif (! str_starts_with($digits, '62')) {
            $digits = '62'.$digits;
        }

        return $digits;
    }

    /** Ringkasan invoice untuk pesan WhatsApp. */
    public static function whatsappMessage(Invoice $invoice): string
    {
        $business = config('crm.business.name');
        $lines = [
            "Halo {$invoice->client?->contact_name},",
            '',
            "Berikut invoice dari {$business}:",
            "No. Invoice: {$invoice->number}",
            'Total: '.rupiah($invoice->total),
            'Jatuh tempo: '.tgl_id($invoice->due_date),
        ];

        $payUrl = self::paymentUrl($invoice);
        if ($payUrl) {
            $lines[] = '';
            $lines[] = 'Lihat detail & konfirmasi pembayaran:';
            $lines[] = $payUrl;
        }

        $lines[] = '';
        $lines[] = 'Terima kasih.';

        return implode("\n", $lines);
    }

    /**
     * Setelah pengiriman pertama berhasil: tandai invoice terkirim (status sent,
     * sent_at terisi, public_token terisi) bila belum final, lalu catat event
     * pengiriman ke activity log untuk riwayat di detail invoice.
     */
    public static function markDelivered(Invoice $invoice, string $event, string $description): void
    {
        if (! $invoice->isTerminal()) {
            $invoice->markSent();
        }

        ActivityLog::record($invoice, $event, $description);
    }
}
