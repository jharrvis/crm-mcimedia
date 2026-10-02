<?php

namespace App\Domains\Security\Enums;

/**
 * Tingkat keparahan insiden keamanan (F3-3).
 *
 * Urutan dari paling berat ke paling ringan dipakai untuk mengurutkan dan
 * meringkas dashboard: critical > high > medium > low > info.
 */
enum IncidentSeverity: string
{
    case Critical = 'critical';
    case High = 'high';
    case Medium = 'medium';
    case Low = 'low';
    case Info = 'info';

    public function label(): string
    {
        return match ($this) {
            self::Critical => 'Kritis',
            self::High => 'Tinggi',
            self::Medium => 'Sedang',
            self::Low => 'Rendah',
            self::Info => 'Info',
        };
    }

    /** Bobot untuk pengurutan (semakin besar semakin berat). */
    public function weight(): int
    {
        return match ($this) {
            self::Critical => 5,
            self::High => 4,
            self::Medium => 3,
            self::Low => 2,
            self::Info => 1,
        };
    }

    /** Kelas Tailwind untuk badge (light + dark). */
    public function badgeClass(): string
    {
        return match ($this) {
            self::Critical => 'bg-red-100 text-red-700 dark:bg-red-900 dark:text-red-200',
            self::High => 'bg-orange-100 text-orange-700 dark:bg-orange-900 dark:text-orange-200',
            self::Medium => 'bg-amber-100 text-amber-700 dark:bg-amber-900 dark:text-amber-200',
            self::Low => 'bg-sky-100 text-sky-700 dark:bg-sky-900 dark:text-sky-200',
            self::Info => 'bg-slate-200 text-slate-600 dark:bg-slate-700 dark:text-slate-300',
        };
    }

    /**
     * Urutan prioritas untuk ORDER BY (CASE ... END): berat dulu.
     *
     * @return array<int, string>
     */
    public static function orderValues(): array
    {
        $cases = self::cases();
        usort($cases, fn (self $a, self $b) => $b->weight() <=> $a->weight());

        return array_map(fn (self $case) => $case->value, $cases);
    }
}
