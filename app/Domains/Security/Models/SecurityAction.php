<?php

namespace App\Domains\Security\Models;

use App\Domains\Clients\Models\Client;
use App\Domains\Core\Traits\LogsActivity;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Jurnal tindakan/perbaikan keamanan (F3-3): catatan apa yang dikerjakan,
 * oleh siapa, dan hasilnya. Menjadi lampiran naratif laporan bulanan.
 */
class SecurityAction extends Model
{
    use HasFactory;
    use LogsActivity;

    protected $fillable = [
        'client_id', 'acted_at', 'action', 'performed_by', 'result',
    ];

    protected function casts(): array
    {
        return ['acted_at' => 'date'];
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function scopeForClient(Builder $query, int $clientId): Builder
    {
        return $query->where('client_id', $clientId);
    }

    public function scopeInMonth(Builder $query, int $year, int $month): Builder
    {
        return $query->whereYear('acted_at', $year)->whereMonth('acted_at', $month);
    }

    public function activityLabel(): string
    {
        return "tindakan keamanan #{$this->getKey()}";
    }
}
