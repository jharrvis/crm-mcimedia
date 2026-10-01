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
