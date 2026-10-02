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

if (! function_exists('crm_normalize_bank_accounts')) {
    /**
     * Normalisasi daftar rekening bank: pertahankan hanya entri yang punya
     * nama bank atau nomor rekening, pangkas spasi, dan seragamkan kunci.
     *
     * @param  mixed  $accounts
     * @return array<int, array{name: string, account_number: string, account_holder: string}>
     */
    function crm_normalize_bank_accounts(mixed $accounts): array
    {
        if (! is_array($accounts)) {
            return [];
        }

        $normalized = [];

        foreach ($accounts as $account) {
            if (! is_array($account)) {
                continue;
            }

            $name = trim((string) ($account['name'] ?? ''));
            $number = trim((string) ($account['account_number'] ?? ''));
            $holder = trim((string) ($account['account_holder'] ?? ''));

            // Lewati entri kosong (mis. hasil parsing JSON yang tidak lengkap).
            if ($name === '' && $number === '') {
                continue;
            }

            $normalized[] = [
                'name' => $name,
                'account_number' => $number,
                'account_holder' => $holder,
            ];
        }

        return $normalized;
    }
}

if (! function_exists('crm_env_bank_accounts')) {
    /**
     * Susun daftar rekening bank dari environment — dipakai config/crm.php.
     *
     * Prioritas:
     *   1. CRM_BANK_ACCOUNTS — JSON array objek, mis.
     *      [{"name":"BCA","account_number":"0000000000","account_holder":"Nama Pemilik"}]
     *   2. Variabel tunggal lama CRM_BANK_NAME / CRM_BANK_ACCOUNT_NUMBER /
     *      CRM_BANK_ACCOUNT_HOLDER (tetap didukung agar .env lama tidak rusak).
     *
     * Selalu mengembalikan array (kosong bila tidak ada rekening yang
     * dikonfigurasi atau JSON tidak valid) — tidak pernah melempar exception.
     *
     * @return array<int, array{name: string, account_number: string, account_holder: string}>
     */
    function crm_env_bank_accounts(): array
    {
        $json = env('CRM_BANK_ACCOUNTS');

        if (is_string($json) && trim($json) !== '') {
            $decoded = json_decode($json, true);

            if (is_array($decoded)) {
                return crm_normalize_bank_accounts($decoded);
            }
        }

        // Fallback: rekening tunggal (format lama).
        return crm_normalize_bank_accounts([[
            'name' => env('CRM_BANK_NAME'),
            'account_number' => env('CRM_BANK_ACCOUNT_NUMBER'),
            'account_holder' => env('CRM_BANK_ACCOUNT_HOLDER'),
        ]]);
    }
}

if (! function_exists('crm_bank_accounts')) {
    /**
     * Daftar rekening bank aktif dari config `crm.bank.accounts`, sudah
     * dinormalisasi. Dipakai PDF invoice, halaman bayar publik, dan email.
     *
     * @return array<int, array{name: string, account_number: string, account_holder: string}>
     */
    function crm_bank_accounts(): array
    {
        return crm_normalize_bank_accounts(config('crm.bank.accounts', []));
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
