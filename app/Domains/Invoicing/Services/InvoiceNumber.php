<?php

namespace App\Domains\Invoicing\Services;

use App\Domains\Invoicing\Models\Invoice;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Generator nomor invoice: INV-YYYYMM-#### (urutan 4 digit, reset tiap bulan).
 *
 * Nomor diambil dari invoice terakhir pada bulan terkait di dalam transaksi DB
 * (dengan lockForUpdate di driver yang mendukung). Kolom unik `number` tetap
 * menjadi penjaga terakhir bila dua transaksi paralel menghasilkan nomor sama.
 */
class InvoiceNumber
{
    public static function next(?Carbon $for = null): string
    {
        $month = $for ? Carbon::parse($for) : Carbon::today();
        $prefix = 'INV-'.$month->format('Ym').'-';

        return DB::transaction(function () use ($prefix) {
            $last = Invoice::where('number', 'like', $prefix.'%')
                ->lockForUpdate()
                ->pluck('number')
                ->map(fn (string $number) => (int) substr($number, strlen($prefix)))
                ->max() ?? 0;

            return $prefix.str_pad((string) ($last + 1), 4, '0', STR_PAD_LEFT);
        });
    }
}
