<?php

namespace App\Domains\Services\Models;

use App\Domains\Clients\Models\Client;
use App\Domains\Core\Traits\LogsActivity;
use App\Domains\Services\Enums\ServiceCycle;
use App\Domains\Services\Enums\ServiceStatus;
use App\Domains\Services\Enums\ServiceType;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

class Service extends Model
{
    use HasFactory;
    use LogsActivity;

    protected $fillable = [
        'client_id', 'type', 'name', 'reference', 'start_date', 'end_date',
        'price', 'cycle', 'status', 'reminder_enabled', 'notes',
    ];

    protected function casts(): array
    {
        return [
            'type' => ServiceType::class,
            'cycle' => ServiceCycle::class,
            'status' => ServiceStatus::class,
            'start_date' => 'date',
            'end_date' => 'date',
            'price' => 'integer',
            'reminder_enabled' => 'boolean',
        ];
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', ServiceStatus::Active);
    }

    /** Layanan aktif, pengingat on, berakhir dalam N hari ke depan (belum lewat). */
    public function scopeExpiringSoon(Builder $query, int $days = 30): Builder
    {
        return $query->where('status', ServiceStatus::Active)
            ->where('reminder_enabled', true)
            ->whereNotNull('end_date')
            ->whereDate('end_date', '>=', Carbon::today())
            ->whereDate('end_date', '<=', Carbon::today()->addDays($days));
    }

    /** Layanan aktif yang tanggal berakhirnya sudah lewat. */
    public function scopeOverdue(Builder $query): Builder
    {
        return $query->where('status', ServiceStatus::Active)
            ->whereNotNull('end_date')
            ->whereDate('end_date', '<', Carbon::today());
    }

    /** Sisa hari hingga berakhir; negatif bila sudah lewat; null bila tanpa tanggal. */
    public function daysUntilEnd(): ?int
    {
        if (! $this->end_date) {
            return null;
        }

        return Carbon::today()->diffInDays($this->end_date, false);
    }

    public function isOverdue(): bool
    {
        return $this->status === ServiceStatus::Active
            && $this->end_date !== null
            && $this->end_date->isPast() && ! $this->end_date->isToday();
    }

    public function activityLabel(): string
    {
        return "layanan {$this->name}";
    }
}
