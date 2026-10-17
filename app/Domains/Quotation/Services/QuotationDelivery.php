<?php

namespace App\Domains\Quotation\Services;

use App\Domains\Core\Models\ActivityLog;
use App\Domains\Invoicing\Services\InvoiceDelivery;
use App\Domains\Quotation\Models\Quotation;
use Illuminate\Support\Str;

/**
 * Logika bersama pengiriman penawaran (Q-2) yang dipakai job email & WhatsApp:
 * penyusunan tautan/ringkasan, pembuatan token publik, normalisasi nomor WA
 * (dipinjam dari `InvoiceDelivery` — mekanisme F2-5 yang sama), dan penandaan
 * penawaran terkirim setelah pengiriman pertama berhasil.
 */
class QuotationDelivery
{
    /** Event activity log untuk riwayat pengiriman & respons klien. */
    public const EVENT_EMAIL = 'email_sent';

    public const EVENT_WHATSAPP = 'whatsapp_sent';

    public const EVENT_ACCEPTED = 'accepted';

    public const EVENT_REJECTED = 'rejected';

    /** Invoice dibuat otomatis dari quotation yang disetujui klien. */
    public const EVENT_INVOICE_AUTO_CREATED = 'invoice_auto_created';

    /** Pembuatan invoice otomatis gagal — admin perlu membuat manual. */
    public const EVENT_AUTO_INVOICE_FAILED = 'auto_invoice_failed';

    /** URL halaman penawaran publik (Q-2) atau null bila tautan belum ada. */
    public static function publicUrl(Quotation $quotation): ?string
    {
        if (blank($quotation->public_token)) {
            return null;
        }

        return route('quotations.public.show', ['token' => $quotation->public_token]);
    }

    /** URL PDF penawaran publik atau null bila tautan belum ada. */
    public static function pdfUrl(Quotation $quotation): ?string
    {
        if (blank($quotation->public_token)) {
            return null;
        }

        return route('quotations.public.pdf', ['token' => $quotation->public_token]);
    }

    /**
     * Pastikan penawaran punya public_token sebelum tautan dikirim.
     * Mekanisme sama dengan F2-4 (Str::random(64)); tidak mengubah status.
     */
    public static function ensurePublicToken(Quotation $quotation): void
    {
        if (blank($quotation->public_token)) {
            $quotation->forceFill(['public_token' => Str::random(64)])->save();
        }
    }

    /** Normalisasi nomor WA ke 62... — jalur F2-5 yang sama, tanpa duplikasi. */
    public static function normalizeWhatsapp(?string $number): ?string
    {
        return InvoiceDelivery::normalizeWhatsapp($number);
    }

    /** Pesan WhatsApp penawaran: nomor, total, masa berlaku, tautan publik. */
    public static function whatsappMessage(Quotation $quotation, ?string $headline = null): string
    {
        $business = config('crm.business.name');
        $lines = [
            "Halo {$quotation->client?->contact_name},",
            '',
            $headline ?? "Berikut penawaran harga dari {$business}:",
            "No. Penawaran: {$quotation->number}",
            'Total: '.rupiah($quotation->total),
            'Berlaku hingga: '.tgl_id($quotation->valid_until),
        ];

        $url = self::publicUrl($quotation);
        if ($url) {
            $lines[] = '';
            $lines[] = 'Lihat detail & setujui/tolak penawaran:';
            $lines[] = $url;
        }

        $lines[] = '';
        $lines[] = 'Terima kasih.';

        return implode("\n", $lines);
    }

    /**
     * Setelah pengiriman pertama berhasil: tandai penawaran terkirim (status
     * sent, sent_at, public_token terisi) bila belum final, lalu catat event
     * pengiriman ke activity log untuk riwayat di detail penawaran.
     */
    public static function markDelivered(Quotation $quotation, string $event, string $description): void
    {
        if (! $quotation->isTerminal()) {
            $quotation->markSent();
        }

        ActivityLog::record($quotation, $event, $description);
    }
}
