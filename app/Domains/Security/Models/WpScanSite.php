<?php

namespace App\Domains\Security\Models;

use App\Domains\Clients\Models\Client;
use App\Domains\Hestia\Models\HestiaAccount;
use App\Domains\Security\Enums\WpScanSiteStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Target scan WPScan otomatis (t_2e555b0b).
 *
 * Baris dibuat otomatis oleh `crm:wpscan` dari akun Hestia aktif yang sudah
 * terpetakan; status deteksi & hasil scan terakhir disimpan di sini (lihat
 * {@see WpScanSiteStatus} untuk makna tiap status).
 */
class WpScanSite extends Model
{
    protected $fillable = [
        'client_id', 'hestia_account_id', 'domain', 'url', 'status',
        'wp_version', 'last_scan_at', 'last_finding_count', 'last_error',
    ];

    protected function casts(): array
    {
        return [
            'status' => WpScanSiteStatus::class,
            'last_scan_at' => 'datetime',
            'last_finding_count' => 'integer',
        ];
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function hestiaAccount(): BelongsTo
    {
        return $this->belongsTo(HestiaAccount::class);
    }

    /**
     * Prefix external_id insiden milik situs ini.
     *
     * Insiden WPScan memakai `wpscan:{site_id}:{fingerprint}` — pola yang sama
     * dengan `disk-quota:{account_id}:{level}` milik alert kuota, sehingga
     * insiden satu situs bisa ditutup massal saat temuannya hilang.
     */
    public function incidentExternalIdPrefix(): string
    {
        return 'wpscan:'.$this->id.':';
    }

    public function scopeScannable(Builder $query): Builder
    {
        return $query->whereIn('status', [WpScanSiteStatus::Active, WpScanSiteStatus::Error]);
    }
}