<?php

namespace App\Domains\Tasks\Enums;

enum TaskStatus: string
{
    // Nilai `open` dipertahankan (data Fase 1) — di papan kanban ia adalah kolom "Todo".
    case Open = 'open';
    case InProgress = 'in_progress';
    case Review = 'review';
    case Done = 'done';

    public function label(): string
    {
        return match ($this) {
            self::Open => 'Todo',
            self::InProgress => 'Dikerjakan',
            self::Review => 'Review',
            self::Done => 'Selesai',
        };
    }

    /** Kelas badge Tailwind (terang + gelap) untuk status ini. */
    public function badgeClasses(): string
    {
        return match ($this) {
            self::Open => 'bg-slate-100 text-slate-600 dark:bg-slate-800 dark:text-slate-300',
            self::InProgress => 'bg-amber-100 text-amber-700 dark:bg-amber-950 dark:text-amber-300',
            self::Review => 'bg-indigo-100 text-indigo-700 dark:bg-indigo-950 dark:text-indigo-300',
            self::Done => 'bg-green-100 text-green-700 dark:bg-green-950 dark:text-green-300',
        };
    }

    public function isDone(): bool
    {
        return $this === self::Done;
    }

    /** Belum selesai — dipakai scope "tugas terbuka". */
    public function isOpen(): bool
    {
        return $this !== self::Done;
    }

    /** Urutan kolom papan kanban: todo -> in-progress -> review -> done. */
    public static function boardColumns(): array
    {
        return [self::Open, self::InProgress, self::Review, self::Done];
    }

    /** @return array<int, string> */
    public static function values(): array
    {
        return array_map(fn (self $status) => $status->value, self::cases());
    }
}
