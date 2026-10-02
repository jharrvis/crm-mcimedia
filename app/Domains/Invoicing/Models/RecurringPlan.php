<?php

namespace App\Domains\Invoicing\Models;

use App\Domains\Clients\Models\Client;
use App\Domains\Core\Traits\LogsActivity;
use App\Domains\Invoicing\Enums\RecurringCycle;
use App\Domains\Services\Models\Service;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * Paket penagihan berulang (F4-11).
 *
 * Menyimpan template invoice: klien, layanan (opsional), judul, siklus
 * penagihan, dan daftar item. `next_invoice_date` adalah AWAL periode
 * penagihan berikutnya — command generator memakainya sebagai titik awal dan
 * terus maju satu siklus setiap kali invoice terbit.
 */
class RecurringPlan extends Model
{
    use HasFactory;
    use LogsActivity;

    protected $fillable = [
        'client_id', 'service_id', 'title', 'cycle', 'notes', 'next_invoice_date',
        'total', 'active', 'auto_send', 'due_days', 'last_generated_at',
    ];

    protected function casts(): array
    {
        return [
            'cycle' => RecurringCycle::class,
            'next_invoice_date' => 'date',
            'total' => 'integer',
            'active' => 'boolean',
            'auto_send' => 'boolean',
            'due_days' => 'integer',
            'last_generated_at' => 'datetime',
        ];
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function service(): BelongsTo
    {
        return $this->belongsTo(Service::class);
    }

    public function invoices(): HasMany
    {
        return $this->hasMany(Invoice::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(RecurringPlanItem::class)->orderBy('sort_order')->orderBy('id');
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('active', true);
    }

    /** Paket aktif yang periode berikutnya sudah jatuh tempo atau sudah lewat. */
    public function scopeDue(Builder $query, ?Carbon $today = null): Builder
    {
        $today = $today ? $today->copy()->startOfDay() : Carbon::today();

        return $query->active()
            ->whereDate('next_invoice_date', '<=', $today);
    }

    /** Awal periode tagihan berikutnya. */
    public function nextPeriodStart(): Carbon
    {
        return $this->next_invoice_date->copy()->startOfDay();
    }

    /** Akhir periode (inclusive) untuk tagihan berikutnya. */
    public function nextPeriodEnd(): Carbon
    {
        return $this->cycle->periodEnd($this->nextPeriodStart());
    }

    /** Sisa hari hingga periode berikutnya; negatif bila sudah lewat. */
    public function daysUntilNext(): int
    {
        return Carbon::today()->diffInDays($this->nextPeriodStart(), false);
    }

    /** Invoice untuk periode tagihan tertentu; dipakai untuk cek idempotensi. */
    public function invoiceForPeriod(Carbon $periodStart): ?Invoice
    {
        return $this->invoices()
            ->whereDate('period_start', $periodStart->toDateString())
            ->first();
    }

    public function activityLabel(): string
    {
        return "paket recurring {$this->title}";
    }
}