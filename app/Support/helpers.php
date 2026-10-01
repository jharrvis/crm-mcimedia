<?php

use Illuminate\Database\Eloquent\Model;
use App\Domains\Core\Models\ActivityLog;

if (! function_exists('rupiah')) {
    /**
     * Format integer IDR: 1500000 -> "Rp 1.500.000"
     */
    function rupiah(int|float|null $amount): string
    {
        return 'Rp '.number_format((int) $amount, 0, ',', '.');
    }
}

if (! function_exists('tgl_id')) {
    /**
     * Format tanggal ringkas: 2026-10-05 -> "05/10/2026"
     */
    function tgl_id(mixed $date): string
    {
        if (! $date) {
            return '—';
        }

        return \Illuminate\Support\Carbon::parse($date)->format('d/m/Y');
    }
}

if (! function_exists('business_logo_path')) {
    /**
     * Path absolut file logo usaha bila dikonfigurasi lewat CRM_BUSINESS_LOGO
     * (relatif terhadap public/) DAN filenya benar-benar ada; selain itu null.
     *
     * Mengembalikan path lokal absolut (public_path) — bukan URL — karena
     * dompdf membaca berkas langsung dari disk. Pemeriksaan is_file() mencegah
     * gambar rusak pada PDF bila path salah/belum diunggah.
     */
    function business_logo_path(): ?string
    {
        $relative = config('crm.business.logo');

        if (! is_string($relative) || trim($relative) === '') {
            return null;
        }

        $path = public_path(trim($relative));

        return is_file($path) ? $path : null;
    }
}

if (! function_exists('business_logo_url')) {
    /**
     * URL publik file logo usaha (untuk halaman web/HTML) bila dikonfigurasi
     * dan filenya ada; null bila tidak. Lihat business_logo_path().
     */
    function business_logo_url(): ?string
    {
        return business_logo_path() === null
            ? null
            : asset(config('crm.business.logo'));
    }
}

if (! function_exists('event_id')) {
    /**
     * Label Indonesia untuk event activity log: created -> "dibuat".
     */
    function event_id(string $event): string
    {
        return match ($event) {
            'created' => 'dibuat',
            'updated' => 'diubah',
            'deleted' => 'dihapus',
            default => $event,
        };
    }
}
