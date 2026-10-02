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
        // F4-13: paket/kuota/status hasil pemecahan payload `raw`.
        'disk_used', 'disk_quota', 'suspended', 'user_suspended',
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
            'disk_used' => 'integer',
            'disk_quota' => 'integer',
            'suspended' => 'boolean',
            'user_suspended' => 'boolean',
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
     * Disuspend di Hestia — di level web domain ATAU di level akun pemiliknya (F4-13).
     */
    public function scopeSuspended(Builder $query): Builder
    {
        return $query->where(fn (Builder $inner) => $inner->where('suspended', true)->orWhere('user_suspended', true));
    }

    /**
     * Kebalikan dari {@see scopeSuspended()}: tidak disuspend sama sekali.
     *
     * Dipakai filter & kartu ringkasan supaya "Aktif" di UI tidak ikut menghitung
     * akun yang faktanya sedang disuspend. Kolom `status` (F3-1) hanya berarti
     * "terlihat pada sinkronisasi terakhir", bukan "berfungsi untuk klien" —
     * suspension adalah konsep terpisah yang tidak ikut mengubah `status`.
     */
    public function scopeNotSuspended(Builder $query): Builder
    {
        return $query->where(fn (Builder $inner) => $inner->where('suspended', false)->where('user_suspended', false));
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

    // ------------------------------------------------------------------
    // Paket, kuota, dan status (F4-13)
    // ------------------------------------------------------------------

    /**
     * Kuota disk benar-benar ada?
     *
     * Hestia memakai `DISK_QUOTA = 0` sebagai "tanpa batas" (konvensi paket),
     * dan `null` berarti Hestia belum melaporkan kuota (mis. versi lama).
     * Keduanya tidak boleh dipakai sebagai pembagi persentase.
     */
    public function hasDiskQuota(): bool
    {
        return $this->disk_quota !== null && $this->disk_quota > 0;
    }

    /**
     * Paket benar-benar tanpa batas (`DISK_QUOTA = 0`) — bukan sekadar tidak
     * dilaporkan. (F4-13)
     *
     * Dipisah dari {@see hasDiskQuota()} supaya UI tidak menampilkan `∞` untuk
     * kuota yang belum diketahui: `∞` adalah klaim "tanpa batas", sedangkan
     * `null` hanya berarti "Hestia belum melapor" — tampilkan `—`.
     */
    public function hasUnlimitedDiskQuota(): bool
    {
        return $this->disk_quota === 0;
    }

    /** Suffix batas kuota untuk UI: angka, `∞` (tanpa batas), atau `—` (belum ada data). */
    public function diskQuotaSuffix(?string $formatted = null): string
    {
        if ($this->hasDiskQuota()) {
            return $formatted ?? (string) $this->disk_quota.' MB';
        }

        return $this->hasUnlimitedDiskQuota() ? '∞' : '—';
    }

    /** Persentase pemakaian disk, atau null bila tidak bisa dihitung. */
    public function diskUsagePercent(): ?int
    {
        if (! $this->hasDiskQuota() || $this->disk_used === null) {
            return null;
        }

        return (int) round($this->disk_used / $this->disk_quota * 100);
    }

    /**
     * True bila domain ini disuspend di Hestia ATAU akun Hestia pemiliknya
     * disuspend. Keduanya membuat domain tak dapat dipakai klien.
     */
    public function isSuspended(): bool
    {
        return $this->suspended || $this->user_suspended;
    }

    /** Label status yang jujur: bedakan "suspend" dari "tidak aktif" biasa. */
    public function statusLabel(): string
    {
        if ($this->isSuspended()) {
            return 'Suspend';
        }

        return $this->status->label();
    }

    /** Badge CSS sesuai status (dipakai view Hestia). */
    public function statusBadgeClass(): string
    {
        return match (true) {
            $this->isSuspended() => 'bg-amber-100 text-amber-800 dark:bg-amber-900 dark:text-amber-200',
            $this->status === HestiaAccountStatus::Active => 'bg-green-100 text-green-700 dark:bg-green-900 dark:text-green-200',
            default => 'bg-slate-200 text-slate-600 dark:bg-slate-700 dark:text-slate-300',
        };
    }
}
