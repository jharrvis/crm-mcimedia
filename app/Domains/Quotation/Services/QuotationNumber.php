<?php

namespace App\Domains\Quotation\Services;

use App\Domains\Quotation\Models\Quotation;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Generator nomor quotation: QUO-YYYYMM-#### (urutan 4 digit, reset tiap bulan).
 *
 * Nomor diambil dari quotation terakhir pada bulan terkait di dalam transaksi DB
 * (dengan lockForUpdate di driver yang mendukung). Kolom unik `number` tetap
 * menjadi penjaga terakhir bila dua transaksi paralel menghasilkan nomor sama.
 */
class QuotationNumber
{
    public static function next(?Carbon $for = null): string
    {
        $month = $for ? Carbon::parse($for) : Carbon::today();
        $prefix = 'QUO-'.$month->format('Ym').'-';

        return DB::transaction(function () use ($prefix) {
            $last = Quotation::where('number', 'like', $prefix.'%')
                ->lockForUpdate()
                ->pluck('number')
                ->map(fn (string $number) => (int) substr($number, strlen($prefix)))
                ->max() ?? 0;

            return $prefix.str_pad((string) ($last + 1), 4, '0', STR_PAD_LEFT);
        });
    }
}