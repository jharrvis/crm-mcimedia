<?php

namespace App\Domains\Security\Models;

use App\Domains\Clients\Models\Client;
use App\Domains\Core\Traits\LogsActivity;
use App\Domains\Security\Enums\IncidentSeverity;
use App\Domains\Security\Enums\IncidentSource;
use App\Domains\Security\Enums\IncidentStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Insiden keamanan (F3-3): temuan dari monitoring/scan atau dicatat manual
 * oleh admin untuk satu klien.
 */
class SecurityIncident extends Model
{
    use HasFactory;
    use LogsActivity;

    protected $fillable = [
        'client_id', 'external_id', 'occurred_at', 'severity', 'source',
        'title', 'description', 'status', 'resolved_at',
        'is_flapping', 'flap_count', 'is_major', 'acknowledged_at', 'wa_notification_meta',
    ];

    protected function casts(): array
    {
        return [
            'severity' => IncidentSeverity::class,
            'source' => IncidentSource::class,
            'status' => IncidentStatus::class,
            'occurred_at' => 'datetime',
            'resolved_at' => 'datetime',
            'acknowledged_at' => 'datetime',
            'is_flapping' => 'boolean',
            'is_major' => 'boolean',
            'flap_count' => 'integer',
            'wa_notification_meta' => 'array',
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

    public function scopeOpen(Builder $query): Builder
    {
        return $query->where('status', IncidentStatus::Open);
    }

    public function scopeSeverity(Builder $query, IncidentSeverity $severity): Builder
    {
        return $query->where('severity', $severity);
    }

    /**
     * Insiden yang memerlukan eskalasi (P1 open > 15 menit, atau P1/P2 open > 30 menit).
     */
    public function scopeNeedsEscalation(Builder $query): Builder
    {
        return $query->open()
            ->where(function ($q) {
                // P1 (critical) open > 15 menit -> naik ke P2
                $q->where('severity', IncidentSeverity::Critical)
                    ->where('occurred_at', '<=', now()->subMinutes(15))
                    ->where('is_major', false);
            })
            ->orWhere(function ($q) {
                // P1/P2 open > 30 menit -> is_major = true
                $q->whereIn('severity', [IncidentSeverity::Critical, IncidentSeverity::High])
                    ->where('occurred_at', '<=', now()->subMinutes(30))
                    ->where('is_major', false);
            });
    }

    /**
     * Cek apakah insiden sudah mendapat notifikasi WA untuk level eskalasi tertentu.
     */
    public function hasWaNotified(string $level): bool
    {
        $meta = $this->wa_notification_meta ?? [];
        return isset($meta[$level]) && $meta[$level] === true;
    }

    /**
     * Tandai notifikasi WA sudah dikirim untuk level tertentu.
     */
    public function markWaNotified(string $level): void
    {
        $meta = $this->wa_notification_meta ?? [];
        $meta[$level] = true;
        $this->update(['wa_notification_meta' => $meta]);
    }

    /**
     * Ubah status insiden. Kolom resolved_at hanya terisi saat status
     * `resolved`; saat dikembalikan ke open/mitigated dikosongkan lagi.
     */
    public function transitionTo(IncidentStatus $status): void
    {
        $this->update([
            'status' => $status,
            'resolved_at' => $status === IncidentStatus::Resolved ? ($this->resolved_at ?? now()) : null,
        ]);
    }

    public function activityLabel(): string
    {
        return "insiden keamanan #{$this->getKey()} ({$this->title})";
    }
}