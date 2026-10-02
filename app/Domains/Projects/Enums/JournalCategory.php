<?php

namespace App\Domains\Projects\Enums;

enum JournalCategory: string
{
    case Progress = 'progress';
    case Note = 'note';
    case Blocker = 'blocker';

    public function label(): string
    {
        return match ($this) {
            self::Progress => 'Progress',
            self::Note => 'Catatan',
            self::Blocker => 'Hambatan',
        };
    }

    public function badgeClasses(): string
    {
        return match ($this) {
            self::Progress => 'bg-green-100 text-green-700 dark:bg-green-950 dark:text-green-300',
            self::Note => 'bg-slate-100 text-slate-600 dark:bg-slate-800 dark:text-slate-300',
            self::Blocker => 'bg-red-100 text-red-700 dark:bg-red-950 dark:text-red-300',
        };
    }
}
