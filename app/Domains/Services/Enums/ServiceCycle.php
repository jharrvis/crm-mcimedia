<?php

namespace App\Domains\Services\Enums;

enum ServiceCycle: string
{
    case Monthly = 'monthly';
    case Yearly = 'yearly';
    case OneTime = 'one_time';

    public function label(): string
    {
        return match ($this) {
            self::Monthly => 'Bulanan',
            self::Yearly => 'Tahunan',
            self::OneTime => 'Sekali bayar',
        };
    }
}
