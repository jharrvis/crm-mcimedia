<?php

namespace App\Domains\Hestia\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Sesi sinkronisasi HestiaCP bertahap / per batch (t_dcccffd9).
 *
 * Menyimpan state satu sesi sync yang dipecah oleh `HestiaBatchSyncService`:
 * daftar user Hestia yang ditarik SEKALI di awal (supaya total akun diketahui
 * sejak request pertama dan slice batch selalu stabil), posisi `next_offset`,
 * counter hasil, akumulasi `seen_keys` (dipakai di batch terakhir untuk
 * menonaktifkan akun yang hilang), serta daftar error per akun.
 *
 * `status` mengikuti pola `HestiaSyncLog`; satu sesi menulis SATU baris
 * `hestia_sync_logs` (dibuat saat start, ditutup saat finalize).
 */
class HestiaSyncBatch extends Model
{
    public const STATUS_RUNNING = 'running';

    public const STATUS_SUCCESS = 'success';

    public const STATUS_FAILED = 'failed';

    protected $fillable = [
        'hestia_server_id', 'hestia_sync_log_id', 'status',
        'total_users', 'processed_users', 'next_offset', 'batch_size',
        'users', 'seen_keys', 'errors',
        'pulled', 'created', 'updated', 'deactivated', 'unmapped',
        'message', 'started_at', 'finished_at',
    ];

    protected function casts(): array
    {
        return [
            'users' => 'array',
            'seen_keys' => 'array',
            'errors' => 'array',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    public function server(): BelongsTo
    {
        return $this->belongsTo(HestiaServer::class, 'hestia_server_id');
    }

    public function syncLog(): BelongsTo
    {
        return $this->belongsTo(HestiaSyncLog::class, 'hestia_sync_log_id');
    }

    public function isRunning(): bool
    {
        return $this->status === self::STATUS_RUNNING;
    }

    /** True bila seluruh user pada daftar sudah diproses (berhasil maupun gagal). */
    public function isComplete(): bool
    {
        return $this->next_offset >= $this->total_users;
    }

    /** Jumlah akun (user Hestia) yang gagal ditarik pada sesi ini. */
    public function failedCount(): int
    {
        return count($this->errors ?? []);
    }
}
