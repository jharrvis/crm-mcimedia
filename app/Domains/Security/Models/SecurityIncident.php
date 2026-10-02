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
    ];

    protected function casts(): array
    {
        return [
            'severity' => IncidentSeverity::class,
            'source' => IncidentSource::class,
            'status' => IncidentStatus::class,
            'occurred_at' => 'datetime',
            'resolved_at' => 'datetime',
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
