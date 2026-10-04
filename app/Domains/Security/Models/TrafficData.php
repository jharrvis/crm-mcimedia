<?php

namespace App\Domains\Security\Models;

use App\Domains\Clients\Models\Client;
use App\Domains\Core\Traits\LogsActivity;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Data traffic (requests/detik, bandwidth) per site.
 * Data dikirim via POST /api/security/monitoring/traffic oleh script aggregator.
 */
class TrafficData extends Model
{
    use HasFactory;
    use LogsActivity;

    protected $table = 'traffic_data';

    protected $fillable = [
        'client_id', 'site', 'timestamp', 'requests_per_second', 'bytes_in', 'bytes_out',
    ];

    protected function casts(): array
    {
        return [
            'timestamp' => 'datetime',
            'requests_per_second' => 'float',
            'bytes_in' => 'integer',
            'bytes_out' => 'integer',
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

    public function scopeForSite(Builder $query, string $site): Builder
    {
        return $query->where('site', $site);
    }

    public function scopeInRange(Builder $query, int $afterSeconds): Builder
    {
        return $query->where('timestamp', '>=', now()->subSeconds($afterSeconds));
    }

    public function scopeLastHour(Builder $query): Builder
    {
        return $query->where('timestamp', '>=', now()->subHour());
    }

    public function scopeLast24Hours(Builder $query): Builder
    {
        return $query->where('timestamp', '>=', now()->subHours(24));
    }

    public function scopeLast7Days(Builder $query): Builder
    {
        return $query->where('timestamp', '>=', now()->subDays(7));
    }

    public function totalBytes(): int
    {
        return $this->bytes_in + $this->bytes_out;
    }

    public function activityLabel(): string
    {
        return "traffic data #{$this->getKey()} ({$this->site} @ {$this->timestamp})";
    }
}