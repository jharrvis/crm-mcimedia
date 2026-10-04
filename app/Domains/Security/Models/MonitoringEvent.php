<?php

namespace App\Domains\Security\Models;

use App\Domains\Clients\Models\Client;
use App\Domains\Core\Traits\LogsActivity;
use App\Domains\Security\Enums\IncidentSeverity;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Event keamanan dari monitoring aggregator (WAF block, brute force, rate limit).
 * Data dikirim via POST /api/security/monitoring/events oleh script aggregator.
 */
class MonitoringEvent extends Model
{
    use HasFactory;
    use LogsActivity;

    protected $table = 'monitoring_events';

    protected $fillable = [
        'client_id', 'external_id', 'occurred_at', 'type', 'ip',
        'target_url', 'country', 'severity', 'details',
    ];

    protected function casts(): array
    {
        return [
            'occurred_at' => 'datetime',
            'severity' => IncidentSeverity::class,
            'details' => 'array',
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

    public function scopeType(Builder $query, string $type): Builder
    {
        return $query->where('type', $type);
    }

    public function scopeSeverity(Builder $query, IncidentSeverity $severity): Builder
    {
        return $query->where('severity', $severity);
    }

    public function scopeRecent(Builder $query, int $hours = 24): Builder
    {
        return $query->where('occurred_at', '>=', now()->subHours($hours));
    }

    public function scopeToday(Builder $query): Builder
    {
        return $query->whereDate('occurred_at', today());
    }

    public function typeLabel(): string
    {
        return match ($this->type) {
            'waf_block' => 'WAF Block',
            'brute_force' => 'Brute Force',
            'rate_limit' => 'Rate Limit',
            default => $this->type,
        };
    }

    public function activityLabel(): string
    {
        return "monitoring event #{$this->getKey()} ({$this->typeLabel()} from {$this->ip})";
    }
}