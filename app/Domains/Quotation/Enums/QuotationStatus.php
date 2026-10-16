<?php

namespace App\Domains\Quotation\Enums;

enum QuotationStatus: string
{
    case Draft = 'draft';
    case Sent = 'sent';
    case Accepted = 'accepted';
    case Rejected = 'rejected';
    case Expired = 'expired';
    case Converted = 'converted';

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Draf',
            self::Sent => 'Terkirim',
            self::Accepted => 'Disetujui',
            self::Rejected => 'Ditolak',
            self::Expired => 'Kedaluwarsa',
            self::Converted => 'Dijadikan Invoice',
        };
    }
}
