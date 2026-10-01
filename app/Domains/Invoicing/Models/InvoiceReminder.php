<?php

namespace App\Domains\Invoicing\Models;

use App\Domains\Invoicing\Enums\InvoiceReminderKind;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Jejak pengingat invoice jatuh tempo (F2-6): satu baris per invoice + kind +
 * channel yang benar-benar terkirim. Dipakai untuk idempotensi command harian.
 */
class InvoiceReminder extends Model
{
    protected $fillable = [
        'invoice_id', 'kind', 'channel', 'sent_at',
    ];

    protected function casts(): array
    {
        return [
            'kind' => InvoiceReminderKind::class,
            'sent_at' => 'datetime',
        ];
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }
}
