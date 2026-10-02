<?php

namespace App\Domains\Hestia\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Riwayat satu kali proses sinkronisasi HestiaCP (F3-1).
 *
 * F4-12: `hestia_server_id` mencatat server asal proses tersebut — null untuk
 * sinkronisasi lewat environment (path F3-1). Satu proses = satu server, sehingga
 * riwayat tidak lagi tercampur antar-server.
 */
class HestiaSyncLog extends Model
{
    public const STATUS_RUNNING = 'running';

    public const STATUS_SUCCESS = 'success';

    public const STATUS_FAILED = 'failed';

    protected $fillable = [
        'hestia_server_id', 'status', 'pulled', 'created', 'updated', 'deactivated', 'unmapped',
        'message', 'started_at', 'finished_at',
    ];

    protected function casts(): array
    {
        return [
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    public function server(): BelongsTo
    {
        return $this->belongsTo(HestiaServer::class, 'hestia_server_id');
    }

    public function isSuccess(): bool
    {
        return $this->status === self::STATUS_SUCCESS;
    }

    /** Label sumber sinkronisasi untuk ditampilkan di UI. */
    public function sourceLabel(): string
    {
        return $this->server?->name ?? 'Environment (.env)';
    }
}
