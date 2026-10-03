<?php

namespace App\Domains\Hestia\Models;

use App\Domains\Core\Traits\LogsActivity;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/**
 * Server HestiaCP yang disinkronkan ke CRM (F4-12).
 *
 * Satu baris = satu instalasi HestiaCP (mis. sg2, YIARI, PA Salatiga).
 * Parameter koneksi non-rahasia disimpan sebagai kolom; KREDENSIAL disimpan
 * pada `credentials` sebagai JSON terenkripsi (cast `encrypted:array`) dan
 * tidak pernah dirender utuh ke UI maupun ditulis ke log.
 *
 * `code` adalah slug stabil yang menyisipkan diri ke `external_key` akun
 * (`srv:<code>:dom:<user>:<domain>`) sehingga dua server tidak pernah
 * menabrak akun yang sama.
 */
class HestiaServer extends Model
{
    use HasFactory;
    use LogsActivity;

    public const STATUS_SUCCESS = 'success';

    public const STATUS_FAILED = 'failed';

    /** Field kredensial yang boleh ditampilkan ulang di form (bukan rahasia). */
    protected $fillable = [
        'name', 'code', 'host', 'port', 'scheme', 'verify_ssl', 'timeout',
        'netdata_host', 'netdata_port',
        'credentials', 'is_active', 'notes',
        'last_sync_at', 'last_sync_status', 'last_synced_at', 'last_sync_message',
    ];

    protected function casts(): array
    {
        return [
            'credentials' => 'encrypted:array',
            'verify_ssl' => 'boolean',
            'is_active' => 'boolean',
            'port' => 'integer',
            'timeout' => 'integer',
            'netdata_port' => 'integer',
            'last_sync_at' => 'datetime',
            'last_synced_at' => 'datetime',
        ];
    }

    public function activityLabel(): string
    {
        return "Server Hestia \"{$this->name}\"";
    }

    public function accounts(): HasMany
    {
        return $this->hasMany(HestiaAccount::class);
    }

    public function syncLogs(): HasMany
    {
        return $this->hasMany(HestiaSyncLog::class);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    /**
     * Kredensial sebagai array (tidak pernah null). Dipakai `HestiaClient`
     * dan form edit (yang hanya menampilkan field non-rahasia).
     *
     * @return array<string, mixed>
     */
    public function credentialBag(): array
    {
        return is_array($this->credentials) ? $this->credentials : [];
    }

    /** True bila host terisi dan salah satu metode autentikasi tersedia. */
    public function isConfigured(): bool
    {
        if (trim((string) $this->host) === '') {
            return false;
        }

        $bag = $this->credentialBag();

        $hasAccessKey = (string) ($bag['access_key'] ?? '') !== '' && (string) ($bag['secret_key'] ?? '') !== '';
        $hasPassword = (string) ($bag['user'] ?? '') !== '' && (string) ($bag['password'] ?? '') !== '';

        return $hasAccessKey || $hasPassword;
    }

    /**
     * Bentuk konfigurasi yang dipahami `HestiaClient` — menyatukan parameter
     * kolom dan kredensial terenkripsi menjadi satu array. Sengaja TIDAK pernah
     * masuk ke log.
     *
     * @return array<string, mixed>
     */
    public function toClientConfig(): array
    {
        return array_merge([
            'enabled' => (bool) $this->is_active,
            'host' => (string) $this->host,
            'port' => (int) $this->port,
            'scheme' => (string) $this->scheme,
            'verify_ssl' => (bool) $this->verify_ssl,
            'timeout' => (int) $this->timeout,
        ], $this->credentialBag());
    }

    /** Catat hasil sinkronisasi terakhir (dipanggil oleh sync service). */
    public function recordSyncResult(bool $success, ?string $message = null): void
    {
        $now = now();

        $this->forceFill([
            'last_synced_at' => $now,
            'last_sync_at' => $success ? $now : $this->last_sync_at,
            'last_sync_status' => $success ? self::STATUS_SUCCESS : self::STATUS_FAILED,
            'last_sync_message' => $message,
        ])->save();
    }

    /** Slug aman untuk `code`;|max 50| karakter. */
    public static function makeCode(string $name): string
    {
        $slug = Str::slug($name);

        return $slug === '' ? 'server' : Str::limit($slug, 50, '');
    }
}
