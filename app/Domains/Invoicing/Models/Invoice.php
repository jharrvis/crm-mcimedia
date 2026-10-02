<?php

namespace App\Domains\Invoicing\Models;

use App\Domains\Clients\Models\Client;
use App\Domains\Core\Traits\LogsActivity;
use App\Domains\Invoicing\Enums\InvoiceStatus;
use App\Domains\Invoicing\Enums\RecurringCycle;
use App\Domains\Invoicing\Exceptions\InvalidInvoiceTransition;
use App\Domains\Services\Models\Service;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

class Invoice extends Model
{
    use HasFactory;
    use LogsActivity;

    protected $fillable = [
        'client_id', 'service_id', 'number', 'title', 'issue_date', 'due_date',
        'status', 'subtotal', 'total', 'notes', 'public_token', 'sent_at', 'paid_at',
        'recurring_plan_id', 'recurring_cycle', 'period_start', 'period_end',
    ];

    protected function casts(): array
    {
        return [
            'status' => InvoiceStatus::class,
            'recurring_cycle' => RecurringCycle::class,
            'issue_date' => 'date',
            'due_date' => 'date',
            'period_start' => 'date',
            'period_end' => 'date',
            'subtotal' => 'integer',
            'total' => 'integer',
            'sent_at' => 'datetime',
            'paid_at' => 'datetime',
        ];
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    /**
     * Layanan yang dicakup invoice ini. Satu invoice dapat mencakup banyak
     * layanan sekaligus (mis. klien dengan banyak website yang membayar
     * bulanan dalam satu invoice gabungan).
     */
    public function services(): BelongsToMany
    {
        return $this->belongsToMany(Service::class)->orderBy('services.name');
    }

    /** Sinkronkan daftar layanan invoice (dipakai create & update). */
    public function syncServices(array $serviceIds): void
    {
        $this->services()->sync(array_values(array_unique(array_map('intval', $serviceIds))));
    }

    public function items(): HasMany
    {
        return $this->hasMany(InvoiceItem::class)->orderBy('sort_order')->orderBy('id');
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }

    /** Paket recurring yang menerbitkan invoice ini (F4-11); null bila manual. */
    public function recurringPlan(): BelongsTo
    {
        return $this->belongsTo(RecurringPlan::class, 'recurring_plan_id');
    }

    /**
     * Invoice terbit otomatis dari paket recurring (bukan dibuat manual).
     *
     * Dibiarkan true walau paketnya sudah dihapus (`recurring_plan_id` jadi
     * NULL karena ON DELETE SET NULL): invoice tetap berasal dari tagihan
     * berulang, dan jejak siklus/periode-nya masih tersimpan.
     */
    public function isRecurring(): bool
    {
        return $this->recurring_plan_id !== null || $this->recurring_cycle !== null;
    }

    /**
     * Hitung ulang amount tiap item (quantity * unit_price) lalu simpan
     * subtotal/total invoice. Tanpa PPN: total = subtotal (IDR final).
     */
    public function recalculateTotals(): void
    {
        $this->load('items');

        $subtotal = 0;
        foreach ($this->items as $item) {
            $item->amount = $item->quantity * $item->unit_price;
            if ($item->isDirty()) {
                $item->save();
            }
            $subtotal += $item->amount;
        }

        $this->forceFill(['subtotal' => $subtotal, 'total' => $subtotal])->save();
    }

    /** Draft -> terkirim. Paid/cancelled adalah status final: kirim ulang ditolak. */
    public function markSent(): void
    {
        $this->ensureTransitionAllowed('dikirim ulang');

        $attributes = [
            'status' => InvoiceStatus::Sent,
            'sent_at' => $this->sent_at ?? now(),
        ];

        // Tautan pembayaran publik (magic link) dibuat sekali saat invoice dikirim.
        if (blank($this->public_token)) {
            $attributes['public_token'] = Str::random(64);
        }

        $this->update($attributes);
    }

    /** Invoice non-draf yang punya tautan pembayaran publik aktif. */
    public function hasPublicLink(): bool
    {
        return $this->status !== InvoiceStatus::Draft && filled($this->public_token);
    }

    /** -> lunas (status final). Invoice yang sudah lunas/dibatalkan tidak bisa dilunaskan lagi. */
    public function markPaid(): void
    {
        $this->ensureTransitionAllowed('dilunaskan');

        $this->update([
            'status' => InvoiceStatus::Paid,
            'paid_at' => $this->paid_at ?? now(),
        ]);
    }

    /** -> dibatalkan (status final). Invoice lunas tidak bisa dibatalkan. */
    public function cancel(): void
    {
        $this->ensureTransitionAllowed('dibatalkan');

        $this->update(['status' => InvoiceStatus::Cancelled]);
    }

    /** Belum dibayar: terkirim atau sudah ditandai terlambat. */
    public function scopeUnpaid(Builder $query): Builder
    {
        return $query->whereIn('status', [InvoiceStatus::Sent, InvoiceStatus::Overdue]);
    }

    /** Terlambat: status overdue, atau terkirim dengan due_date sudah lewat. */
    public function scopeOverdue(Builder $query): Builder
    {
        return $query->where(function (Builder $q) {
            $q->where('status', InvoiceStatus::Overdue)
                ->orWhere(function (Builder $q) {
                    $q->where('status', InvoiceStatus::Sent)
                        ->whereDate('due_date', '<', Carbon::today());
                });
        });
    }

    public function isTerminal(): bool
    {
        return in_array($this->status, [InvoiceStatus::Paid, InvoiceStatus::Cancelled], true);
    }

    protected function ensureTransitionAllowed(string $action): void
    {
        if ($this->isTerminal()) {
            throw new InvalidInvoiceTransition(
                "Invoice {$this->number} berstatus {$this->status->label()} sehingga tidak dapat {$action}."
            );
        }
    }

    public function activityLabel(): string
    {
        return "invoice {$this->number}";
    }
}
