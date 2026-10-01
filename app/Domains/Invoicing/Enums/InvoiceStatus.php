<?php

namespace App\Domains\Invoicing\Enums;

enum InvoiceStatus: string
{
    case Draft = 'draft';
    case Sent = 'sent';
    case Paid = 'paid';
    case Overdue = 'overdue';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Draf',
            self::Sent => 'Terkirim',
            self::Paid => 'Lunas',
            self::Overdue => 'Terlambat',
            self::Cancelled => 'Dibatalkan',
        };
    }
}
