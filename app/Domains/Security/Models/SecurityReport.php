<?php

namespace App\Domains\Security\Models;

use App\Domains\Clients\Models\Client;
use App\Domains\Core\Traits\LogsActivity;
use App\Domains\Security\Enums\ReportStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Laporan keamanan bulanan (F3-3): metadata + berkas PDF yang diunggah admin.
 * Pengisian template otomatis menyusul di F3-5; pada fase ini PDF diunggah manual.
 */
class SecurityReport extends Model
{
    use HasFactory;
    use LogsActivity;

    protected $fillable = [
        'client_id', 'period', 'file_path', 'status', 'sent_at',
    ];

    protected function casts(): array
    {
        return [
            'status' => ReportStatus::class,
            'sent_at' => 'datetime',
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

    public function scopeSent(Builder $query): Builder
    {
        return $query->where('status', ReportStatus::Sent);
    }

    /** Draf -> terkirim (tampil di halaman publik klien). */
    public function markSent(): void
    {
        if ($this->status === ReportStatus::Sent) {
            return;
        }

        $this->update(['status' => ReportStatus::Sent, 'sent_at' => $this->sent_at ?? now()]);
    }

    public function hasFile(): bool
    {
        return filled($this->file_path);
    }

    /** Nama berkas saat diunduh klien/admin. */
    public function downloadName(): string
    {
        return "Laporan-Keamanan-{$this->client->name}-{$this->period}.pdf";
    }

    public function activityLabel(): string
    {
        return "laporan keamanan {$this->period}";
    }
}
