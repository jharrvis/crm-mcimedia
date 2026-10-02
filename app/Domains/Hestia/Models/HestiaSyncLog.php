<?php

namespace App\Domains\Hestia\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Riwayat satu kali proses sinkronisasi HestiaCP (F3-1).
 */
class HestiaSyncLog extends Model
{
    public const STATUS_RUNNING = 'running';

    public const STATUS_SUCCESS = 'success';

    public const STATUS_FAILED = 'failed';

    protected $fillable = [
        'status', 'pulled', 'created', 'updated', 'deactivated', 'unmapped',
        'message', 'started_at', 'finished_at',
    ];

    protected function casts(): array
    {
        return [
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    public function isSuccess(): bool
    {
        return $this->status === self::STATUS_SUCCESS;
    }
}
