<?php

namespace App\Domains\Projects\Enums;

enum ProjectStatus: string
{
    case New = 'new';
    case InProgress = 'in_progress';
    case OnHold = 'on_hold';
    case Done = 'done';

    public function label(): string
    {
        return match ($this) {
            self::New => 'Baru',
            self::InProgress => 'Berjalan',
            self::OnHold => 'Ditahan',
            self::Done => 'Selesai',
        };
    }
}
