<?php

namespace App\Domains\Invoicing\Services;

use App\Domains\Invoicing\Enums\InvoiceStatus;
use App\Domains\Invoicing\Models\Invoice;
use App\Domains\Invoicing\Models\Payment;
use Illuminate\Support\Facades\Log;

/**
 * QRIS Dinamis dari QRIS statis merchant (GoPay).
 *
 * Mengubah QRIS statis menjadi dinamis (nominal terkunci) dengan memodifikasi
 * payload EMVCo: ubah marker 010211->010212, sisipkan field nominal (tag 54),
 * hitung ulang CRC16-CCITT. Terverifikasi bekerja di MyBCA & DANA (2026-10-18).
 *
 * Konfirmasi pembayaran via webhook dari aplikasi Android (MacroDroid) yang
 * membaca notifikasi GoPay Merchant -> POST /api/webhooks/qris-notify.
 */
class QrisDynamicService
{
    /**
     * Ubah QRIS statis menjadi dinamis dengan nominal tertentu.
     *
     * PENTING: string setelah buang 4 char CRC lama sudah berakhiran "6304"
     * (header field CRC) — JANGAN tambahkan "6304" lagi, cukup tempel CRC baru.
     * Menambahkan ganda membuat payload invalid (bug 2026-10-18).
     */
    public function toDynamic(string $staticQris, int $amount): string
    {
        $d = str_replace('010211', '010212', $staticQris);

        // Buang 4 char CRC lama (string kini berakhiran "6304")
        $d = substr($d, 0, -4);

        // Sisipkan field nominal (tag 54) sebelum country code 5802ID
        $amt = (string) $amount;
        $amtField = '54' . str_pad((string) strlen($amt), 2, '0', STR_PAD_LEFT) . $amt;
        $d = str_replace('5802ID', $amtField . '5802ID', $d);

        // CRC16-CCITT dihitung atas seluruh payload termasuk "6304"
        return $d . self::crc16($d);
    }

    /** CRC16-CCITT: init 0xFFFF, poly 0x1021, output 4 hex kapital. */
    public static function crc16(string $data): string
    {
        $crc = 0xFFFF;
        $len = strlen($data);
        for ($i = 0; $i < $len; $i++) {
            $crc ^= ord($data[$i]) << 8;
            for ($j = 0; $j < 8; $j++) {
                $crc = ($crc & 0x8000) ? (($crc << 1) ^ 0x1021) & 0xFFFF : ($crc << 1) & 0xFFFF;
            }
        }

        return strtoupper(str_pad(dechex($crc), 4, '0', STR_PAD_LEFT));
    }

    /** Apakah fitur QRIS dinamis aktif (config lengkap)? */
    public function isEnabled(): bool
    {
        return (bool) config('qris.enabled') && filled(config('qris.static_payload'));
    }

    /**
     * Kode unik 3 digit untuk invoice (100-999), disimpan persisten agar
     * nominal bayar selalu sama untuk invoice yang sama.
     */
    public function uniqueCode(Invoice $invoice): int
    {
        if ($invoice->qris_unique_code) {
            return (int) $invoice->qris_unique_code;
        }

        // Deterministik dari ID agar stabil, fallback random bila tabrakan
        $code = 100 + ($invoice->id % 900);
        $tries = 0;
        while ($tries < 10 && Invoice::where('qris_unique_code', $code)
            ->where('id', '!=', $invoice->id)
            ->whereIn('status', [InvoiceStatus::Sent, InvoiceStatus::Overdue])
            ->exists()
        ) {
            $code = random_int(100, 999);
            $tries++;
        }

        $invoice->update(['qris_unique_code' => $code]);

        return $code;
    }

    /** Nominal yang harus dibayar klien via QRIS (total + kode unik). */
    public function payableAmount(Invoice $invoice): int
    {
        return (int) $invoice->total + $this->uniqueCode($invoice);
    }

    /** Generate payload QRIS dinamis untuk invoice. */
    public function payloadFor(Invoice $invoice): ?string
    {
        if (! $this->isEnabled()) {
            return null;
        }

        return $this->toDynamic(
            config('qris.static_payload'),
            $this->payableAmount($invoice)
        );
    }

    /**
     * Tangani webhook notifikasi pembayaran dari aplikasi Android.
     * Mencocokkan nominal (termasuk kode unik) ke invoice yang belum lunas.
     */
    public function handleNotify(array $payload, ?string $secret): bool
    {
        $expected = config('qris.webhook_secret');

        if (! $expected || ! hash_equals($expected, (string) $secret)) {
            Log::warning('QRIS notify: secret tidak valid');

            return false;
        }

        $text = $payload['text'] ?? $payload['notification_text'] ?? '';
        $amount = $this->parseAmount((string) $text);

        if (! $amount) {
            Log::warning('QRIS notify: nominal tidak terparse', ['text' => mb_substr((string) $text, 0, 200)]);

            return false;
        }

        // Idempotency: abaikan notifikasi yang sudah diproses
        $hash = hash('sha256', $text . '|' . ($payload['posted_at'] ?? ''));
        if (\Illuminate\Support\Facades\Cache::has('qris-notify:' . $hash)) {
            Log::info('QRIS notify: duplikat diabaikan', ['amount' => $amount]);

            return true;
        }

        // Cari invoice belum lunas dengan nominal cocok (total + kode unik)
        // dalam jendela 24 jam terakhir
        $invoice = Invoice::whereIn('status', [InvoiceStatus::Sent, InvoiceStatus::Overdue])
            ->whereNotNull('qris_unique_code')
            ->whereRaw('(total + qris_unique_code) = ?', [$amount])
            ->where('updated_at', '>=', now()->subDay())
            ->orderByDesc('updated_at')
            ->first();

        if (! $invoice) {
            Log::warning('QRIS notify: invoice tidak ditemukan', ['amount' => $amount]);

            return false;
        }

        if ($invoice->status === InvoiceStatus::Paid) {
            return true;
        }

        \Illuminate\Support\Facades\Cache::put('qris-notify:' . $hash, true, now()->addDays(7));

        $invoice->markPaid();

        Payment::create([
            'invoice_id' => $invoice->id,
            'amount' => $amount,
            'method' => 'qris_dynamic',
            'status' => 'confirmed',
            'paid_at' => now(),
            'note' => 'QRIS dinamis via notifikasi GoPay Merchant',
        ]);

        Log::info('QRIS notify: invoice lunas', [
            'invoice' => $invoice->number,
            'amount' => $amount,
        ]);

        return true;
    }

    /**
     * Parse nominal rupiah dari teks notifikasi.
     * Mendukung format: "Rp1.500.123", "Rp 1.500.123", "1500123".
     */
    public function parseAmount(string $text): int
    {
        // Cari pola Rp diikuti angka dengan pemisah ribuan
        if (preg_match('/Rp\.?\s*([\d.]+)/i', $text, $m)) {
            $num = (int) str_replace('.', '', $m[1]);
            if ($num > 0) {
                return $num;
            }
        }

        return 0;
    }
}
