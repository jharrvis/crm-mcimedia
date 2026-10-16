<?php

namespace App\Domains\Quotation\Models;

use App\Domains\Clients\Models\Client;
use App\Domains\Core\Traits\LogsActivity;
use App\Domains\Invoicing\Models\Invoice;
use App\Domains\Quotation\Enums\QuotationStatus;
use App\Domains\Quotation\Exceptions\InvalidQuotationTransition;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Quotation extends Model
{
    use HasFactory;
    use LogsActivity;

    protected $fillable = [
        'client_id', 'number', 'title', 'issue_date', 'valid_until', 'status',
        'subtotal', 'discount', 'total', 'notes', 'terms', 'public_token',
        'sent_at', 'responded_at', 'converted_invoice_id',
    ];

    protected function casts(): array
    {
        return [
            'status' => QuotationStatus::class,
            'issue_date' => 'date',
            'valid_until' => 'date',
            'subtotal' => 'integer',
            'discount' => 'integer',
            'total' => 'integer',
            'sent_at' => 'datetime',
            'responded_at' => 'datetime',
            'converted_invoice_id' => 'integer',
        ];
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(QuotationItem::class)->orderBy('sort_order')->orderBy('id');
    }

    /** Invoice hasil konversi quotation ini; null bila belum dikonversi. */
    public function convertedInvoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class, 'converted_invoice_id');
    }

    /**
     * Hitung ulang amount tiap item (quantity * unit_price) lalu simpan
     * subtotal/total quotation. Tanpa PPN: total = subtotal - discount,
     * di-clamp ke 0 (total tidak boleh negatif).
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

        $total = max(0, $subtotal - (int) $this->discount);

        $this->forceFill(['subtotal' => $subtotal, 'total' => $total])->save();
    }

    /** Draft -> terkirim. Status terminal (accepted/rejected/expired/converted) ditolak. */
    public function markSent(): void
    {
        $this->ensureTransitionAllowed('dikirim ulang');

        $this->update([
            'status' => QuotationStatus::Sent,
            'sent_at' => $this->sent_at ?? now(),
        ]);
    }

    /** -> disetujui (status final). */
    public function markAccepted(): void
    {
        $this->ensureTransitionAllowed('disetujui');

        $this->update([
            'status' => QuotationStatus::Accepted,
            'responded_at' => $this->responded_at ?? now(),
        ]);
    }

    /** -> ditolak (status final). */
    public function markRejected(): void
    {
        $this->ensureTransitionAllowed('ditolak');

        $this->update([
            'status' => QuotationStatus::Rejected,
            'responded_at' => $this->responded_at ?? now(),
        ]);
    }

    /** -> kedaluwarsa (status final). */
    public function markExpired(): void
    {
        $this->ensureTransitionAllowed('ditandai kedaluwarsa');

        $this->update(['status' => QuotationStatus::Expired]);
    }

    /** -> dijadikan invoice (status final). */
    public function markConverted(Invoice $invoice): void
    {
        $this->ensureTransitionAllowed('dijadikan invoice');

        $this->update([
            'status' => QuotationStatus::Converted,
            'converted_invoice_id' => $invoice->id,
        ]);
    }

    /** Penawaran terkirim yang menunggu respons klien. */
    public function scopePending(Builder $query): Builder
    {
        return $query->where('status', QuotationStatus::Sent);
    }

    /** Penawaran yang masih bisa ditindaklanjuti (draf atau terkirim). */
    public function scopeActionable(Builder $query): Builder
    {
        return $query->whereIn('status', [QuotationStatus::Draft, QuotationStatus::Sent]);
    }

    public function isTerminal(): bool
    {
        return in_array($this->status, [
            QuotationStatus::Accepted,
            QuotationStatus::Rejected,
            QuotationStatus::Expired,
            QuotationStatus::Converted,
        ], true);
    }

    protected function ensureTransitionAllowed(string $action): void
    {
        if ($this->isTerminal()) {
            throw new InvalidQuotationTransition(
                "Penawaran {$this->number} berstatus {$this->status->label()} sehingga tidak dapat {$action}."
            );
        }
    }

    public function activityLabel(): string
    {
        return "penawaran {$this->number}";
    }
}
