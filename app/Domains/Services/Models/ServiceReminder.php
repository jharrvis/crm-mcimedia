<?php

namespace App\Domains\Services\Models;

use App\Domains\Services\Enums\ServiceReminderKind;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Jejak reminder WhatsApp perpanjangan layanan (t_cc560a11): satu baris per
 * layanan + kind + channel yang benar-benar terkirim. Dipakai untuk
 * idempotensi command harian `crm:send-service-reminders`.
 */
class ServiceReminder extends Model
{
    protected $fillable = [
        'service_id', 'kind', 'channel', 'sent_at',
    ];

    protected function casts(): array
    {
        return [
            'kind' => ServiceReminderKind::class,
            'sent_at' => 'datetime',
        ];
    }

    public function service(): BelongsTo
    {
        return $this->belongsTo(Service::class);
    }
}
