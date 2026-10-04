<?php

namespace App\Domains\Security\Models;

use App\Domains\Clients\Models\Client;
use App\Domains\Core\Traits\LogsActivity;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Sertifikat SSL domain klien.
 * Data dikirim via POST /api/security/monitoring/ssl oleh script aggregator.
 */
class SslCertificate extends Model
{
    use HasFactory;
    use LogsActivity;

    protected $table = 'ssl_certificates';

    protected $fillable = [
        'client_id', 'domain', 'issuer', 'expires_at', 'status', 'san_domains', 'last_checked_at',
    ];

    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
            'last_checked_at' => 'datetime',
            'san_domains' => 'array',
        ];
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function scopeForClient(Builder $query, int $clientId): Builder
    {
        return $query->where('client_id', $clientId);
    }

    public function scopeValid(Builder $query): Builder
    {
        return $query->where('status', 'valid');
    }

    public function scopeExpiring(Builder $query): Builder
    {
        return $query->where('status', 'expiring');
    }

    public function scopeExpired(Builder $query): Builder
    {
        return $query->where('status', 'expired');
    }

    public function scopeExpiringWithin(Builder $query, int $days): Builder
    {
        return $query->where('status', '!=', 'expired')
            ->where('expires_at', '<=', now()->addDays($days))
            ->where('expires_at', '>=', now());
    }

    public function daysUntilExpiry(): ?int
    {
        if (! $this->expires_at) {
            return null;
        }
        return max(0, (int) now()->diffInDays($this->expires_at, false));
    }

    public function isExpiringSoon(int $days = 14): bool
    {
        $daysLeft = $this->daysUntilExpiry();
        return $daysLeft !== null && $daysLeft <= $days && $daysLeft >= 0;
    }

    public function activityLabel(): string
    {
        return "SSL certificate #{$this->getKey()} ({$this->domain})";
    }
}