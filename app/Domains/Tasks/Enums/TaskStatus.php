<?php

namespace App\Domains\Tasks\Enums;

enum TaskStatus: string
{
    case Open = 'open';
    case Done = 'done';

    public function label(): string
    {
        return match ($this) {
            self::Open => 'Terbuka',
            self::Done => 'Selesai',
        };
    }
}
