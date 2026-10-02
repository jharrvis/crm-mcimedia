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
        'external_key', 'hestia_server_id', 'hestia_user', 'domain', 'plan', 'service_type',
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

    public function server(): BelongsTo
    {
        return $this->belongsTo(HestiaServer::class, 'hestia_server_id');
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

    /**
     * Batasi query ke satu server tertentu.
     *
     * Dipakai `deactivateMissing()` (F4-12): tanpa scope ini, sinkronisasi
     * server A akan menonaktifkan akun milik server B yang memang masih ada
     * di Hestia-nya masing-masing.
     *
     * @param  HestiaServer|int|null  $server  null = akun yang belum punya server (path env F3-1)
     */
    public function scopeForServer(Builder $query, HestiaServer|int|null $server = null): Builder
    {
        if ($server === null) {
            return $query->whereNull('hestia_server_id');
        }

        return $query->where('hestia_server_id', $server instanceof HestiaServer ? $server->id : $server);
    }

    /**
     * Kunci stabil untuk satu web domain pada satu akun Hestia (F3-1).
     *
     * F4-12: bila `serverCode` diisi, kunci dik-prefix `srv:<code>:` supaya
     * domain & username yang sama di dua server berbeda tidak saling menimpa.
     * Parameter opsional ditaruh TERAKHIR agar signature lama tetap kompatibel.
     */
    public static function keyFor(string $hestiaUser, string $domain, ?string $serverCode = null): string
    {
        $key = 'dom:'.mb_strtolower($hestiaUser).':'.mb_strtolower($domain);

        if ($serverCode === null || trim($serverCode) === '') {
            return $key;
        }

        return 'srv:'.mb_strtolower(trim($serverCode)).':'.$key;
    }

    public function isMapped(): bool
    {
        return $this->mapping_status->isResolved() && $this->service_id !== null;
    }
}
