<?php

namespace App\Domains\Hestia\Models;

use App\Domains\Clients\Models\Client;
use App\Domains\Hestia\Enums\HestiaAccountStatus;
use App\Domains\Hestia\Enums\HestiaMappingStatus;
use App\Domains\Services\Models\Service;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Akun (web domain) hasil sinkronisasi HestiaCP (F3-1).
 */
class HestiaAccount extends Model
{
    protected $fillable = [
        'external_key', 'hestia_user', 'domain', 'plan', 'service_type',
        'start_date', 'end_date', 'status', 'mapping_status',
        'client_id', 'service_id', 'raw',
        'first_seen_at', 'last_seen_at',
    ];

    protected function casts(): array
    {
        return [
            'status' => HestiaAccountStatus::class,
            'mapping_status' => HestiaMappingStatus::class,
            'start_date' => 'date',
            'end_date' => 'date',
            'raw' => 'array',
            'first_seen_at' => 'datetime',
            'last_seen_at' => 'datetime',
        ];
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function service(): BelongsTo
    {
        return $this->belongsTo(Service::class);
    }

    public function scopeUnmapped(Builder $query): Builder
    {
        return $query->where('mapping_status', HestiaMappingStatus::Unmapped);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', HestiaAccountStatus::Active);
    }

    /** Kunci stabil untuk satu web domain pada satu akun Hestia. */
    public static function keyFor(string $hestiaUser, string $domain): string
    {
        return 'dom:'.mb_strtolower($hestiaUser).':'.mb_strtolower($domain);
    }

    public function isMapped(): bool
    {
        return $this->mapping_status->isResolved() && $this->service_id !== null;
    }
}
