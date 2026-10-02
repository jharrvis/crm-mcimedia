<?php

namespace App\Domains\Projects\Enums;

use Illuminate\Support\Carbon;

enum ReportPeriod: string
{
    case Week = 'week';
    case Month = 'month';

    public function label(): string
    {
        return match ($this) {
            self::Week => 'Minggu',
            self::Month => 'Bulanan',
        };
    }

    /** @return array<int, string> */
    public static function values(): array
    {
        return array_map(fn (self $period) => $period->value, self::cases());
    }

    /**
     * Rentang tanggal periode yang memuat $anchor.
     * - week : Senin s/d Minggu (minggu yang memuat anchor).
     * - month: tanggal 1 s/d hari terakhir bulan anchor.
     *
     * @return array{0: Carbon, 1: Carbon} [start, end] (inklusif, jam 00:00)
     */
    public function rangeFor(Carbon $anchor): array
    {
        $anchor = $anchor->copy()->startOfDay();

        return match ($this) {
            self::Week => [$anchor->copy()->startOfWeek(Carbon::MONDAY), $anchor->copy()->endOfWeek(Carbon::SUNDAY)->startOfDay()],
            self::Month => [$anchor->copy()->startOfMonth(), $anchor->copy()->endOfMonth()->startOfDay()],
        };
    }

    /** Label periode manusiawi, mis. "Minggu 06/10/2026 – 12/10/2026". */
    public function rangeLabel(Carbon $start, Carbon $end): string
    {
        $format = fn (Carbon $d) => $d->format('d/m/Y');

        return match ($this) {
            self::Week => "Minggu {$format($start)} – {$format($end)}",
            self::Month => 'Bulan '.self::monthName($start->month).' '.$start->year,
        };
    }

    /** Nama bulan Indonesia (locale aplikasi bisa 'en'; label tetap Indonesia). */
    public static function monthName(int $month): string
    {
        return [
            1 => 'Januari', 2 => 'Februari', 3 => 'Maret', 4 => 'April',
            5 => 'Mei', 6 => 'Juni', 7 => 'Juli', 8 => 'Agustus',
            9 => 'September', 10 => 'Oktober', 11 => 'November', 12 => 'Desember',
        ][$month] ?? (string) $month;
    }
}
