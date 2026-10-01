<?php

namespace App\Domains\Invoicing\Models;

use App\Domains\Clients\Models\Client;
use App\Domains\Core\Traits\LogsActivity;
use App\Domains\Invoicing\Enums\InvoiceStatus;
use App\Domains\Invoicing\Exceptions\InvalidInvoiceTransition;
use App\Domains\Services\Models\Service;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

class Invoice extends Model
{
    use HasFactory;
    use LogsActivity;

    protected $fillable = [
        'client_id', 'service_id', 'number', 'title', 'issue_date', 'due_date',
        'status', 'subtotal', 'total', 'notes', 'public_token', 'sent_at', 'paid_at',
    ];

    protected function casts(): array
    {
        return [
            'status' => InvoiceStatus::class,
            'issue_date' => 'date',
            'due_date' => 'date',
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

    public function service(): BelongsTo
    {
        return $this->belongsTo(Service::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(InvoiceItem::class)->orderBy('sort_order')->orderBy('id');
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
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

        $this->update([
            'status' => InvoiceStatus::Sent,
            'sent_at' => $this->sent_at ?? now(),
        ]);
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
