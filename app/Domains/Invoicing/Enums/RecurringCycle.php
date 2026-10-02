<?php

namespace App\Domains\Invoicing\Enums;

use Illuminate\Support\Carbon;

/**
 * Siklus penagihan berulang (F4-11): bulanan, 3 bulan, 6 bulan, atau tahunan.
 *
 * Siklus layanan lama (ServiceCycle: monthly|yearly|one_time) menjelaskan
 * bagaimana sebuah layanan dijadwalkan; enum ini menjelaskan berapa jauh
 * ke depan invoice berikutnya dibuat untuk satu paket recurring.
 */
enum RecurringCycle: string
{
    case Monthly = 'monthly';
    case Quarterly = 'quarterly';
    case Semiannual = 'semiannual';
    case Yearly = 'yearly';

    /** Jumlah bulan dalam satu siklus penagihan. */
    public function months(): int
    {
        return match ($this) {
            self::Monthly => 1,
            self::Quarterly => 3,
            self::Semiannual => 6,
            self::Yearly => 12,
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::Monthly => 'Bulanan',
            self::Quarterly => 'Per 3 Bulan',
            self::Semiannual => 'Per 6 Bulan',
            self::Yearly => 'Tahunan',
        };
    }

    /** Label ringkas untuk badge/daftar dropdown. */
    public function shortLabel(): string
    {
        return match ($this) {
            self::Monthly => '1 bulan',
            self::Quarterly => '3 bulan',
            self::Semiannual => '6 bulan',
            self::Yearly => '12 bulan',
        };
    }

    /**
     * Akhir periode yang dimulai pada $start, yaitu satu siklus penuh
     * (inclusive).
     *
     * Panjang periode dihitung dari awal periode berikutnya, bukan dari
     * pengurangan satu hari: 1Jan + 1 bulan → 1Feb, jadi periodenya 1Jan–31Jan.
     * Bila tanggal awal tidak ada di bulan tujuan (mis. 31Jan → bulan Feb),
     * periode justru berakhir di hari terakhir bulan tujuan (31Jan–28Feb),
     * bukan 27Feb — agar tidak ada hari yang hilang dari penagihan.
     */
    public function periodEnd(Carbon $start): Carbon
    {
        $start = $start->copy()->startOfDay();

        $next = $start->copy()->addMonthsNoOverflow($this->months());

        // addMonthsNoOverflow "membulatkan" ke hari terakhir bulan tujuan bila
        // tanggal awal tidak muat (31Jan + 1 bulan = 28Feb). Dalam kasus itu
        // periode berakhir di akhir bulan tujuan.
        if ($next->day !== $start->day) {
            return $start->copy()
                ->addMonthsNoOverflow($this->months())
                ->endOfMonth();
        }

        return $next->copy()->subDay();
    }

    /** Tagihan berikutnya: awal periode setelah periode yang berakhir di $periodEnd. */
    public function nextPeriodStart(Carbon $periodEnd): Carbon
    {
        return $periodEnd->copy()->startOfDay()->addDay();
    }
}