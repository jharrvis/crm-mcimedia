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
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class Invoice extends Model
{
    use HasFactory;
    use LogsActivity;

    protected $fillable = [
        'client_id', 'service_id', 'number', 'title', 'issue_date', 'due_date',
        'status', 'subtotal', 'total', 'notes', 'public_token', 'sent_at', 'paid_at',
        'parent_invoice_id', 'termin_percent',
        'recurring_plan_id', 'recurring_cycle', 'period_start', 'period_end',
        'midtrans_order_id', 'midtrans_snap_token', 'midtrans_transaction_status',
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
            'parent_invoice_id' => 'integer',
            'termin_percent' => 'decimal:2',
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

    // ---------- Termin pembayaran (F4-10) ----------

    /**
     * Invoice induk dari invoice termin ini. NULL untuk invoice biasa.
     * Invoice termin tetap invoice utuh (nomor, status, pembayaran, PDF,
     * tautan publik sendiri) — hanya ditautkan ke induknya.
     */
    public function parentInvoice(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_invoice_id');
    }

    /** Invoice termin anak dari invoice induk ini. */
    public function terminInvoices(): HasMany
    {
        return $this->hasMany(self::class, 'parent_invoice_id')
            ->orderBy('due_date')
            ->orderBy('id');
    }

    /** Invoice ini adalah termin dari sebuah invoice induk. */
    public function isTermin(): bool
    {
        return $this->parent_invoice_id !== null;
    }

    /** Invoice ini punya minimal satu termin (jadi berperan sebagai induk). */
    public function hasTermins(): bool
    {
        return $this->terminInvoices()->exists();
    }

    /**
     * Invoice induk setelah dipecah hanya menjadi dokumen kontrak: nominalnya
     * sudah tercermin di invoice termin, jadi invoice itu sendiri tidak lagi
     * boleh ditagih (tidak bisa dilunasi, tidak bisa bikin/aktivkan tautan
     * bayar publik). Tanpa guard ini klien bisa membayar nilai kontrak penuh
     * lewat invoice induk DAN lewat termin-nya — penagihan ganda.
     */
    public function isCollectible(): bool
    {
        return ! $this->hasTermins();
    }

    /**
     * Invoice induk hanya boleh dipecah satu kali, dan invoice termin tidak
     * boleh dipecah lagi (tanpa batas tingkat nesting — struktur datanya hanya
     * dirancang untuk satu tingkat).
     */
    public function canSplitIntoTerms(): bool
    {
        return ! $this->hasTermins()
            && ! $this->isTermin()
            && ! $this->isTerminal();
    }

    /**
     * Invoice induk yang sudah dipecah tidak boleh dihapus lewat jalur biasa:
     * cascade ON DELETE akan ikut menghapus termin yang mungkin sudah lunas
     * beserta pembayarannya. Penghapusan aman hanya bila semua termin masih
     * draf (belum ada uang masuk yang tercatat).
     */
    public function canBeDeletedSafely(): bool
    {
        if (! $this->hasTermins()) {
            return true;
        }

        return $this->terminInvoices()
            ->whereIn('status', [InvoiceStatus::Sent, InvoiceStatus::Overdue, InvoiceStatus::Paid])
            ->doesntExist();
    }

    /**
     * Termin yang sudah „dimiliki" invoice induk (bukan draf lagi). Dipakai
     * untuk memblokir perubahan yang membuat nilai kontrak tidak sinkron.
     */
    public function hasNonDraftTerms(): bool
    {
        return $this->terminInvoices()
            ->where('status', '!=', InvoiceStatus::Draft)
            ->exists();
    }

    /** Total persentase termin yang sudah dibuat (dari nilai kontrak induk). */
    public function allocatedTerminPercent(): float
    {
        if (! $this->relationLoaded('terminInvoices')) {
            return (float) round(
                $this->terminInvoices()->sum(DB::raw('COALESCE(termin_percent, 0)')),
                2
            );
        }

        return (float) round(
            $this->terminInvoices->sum(fn ($termin) => (float) $termin->termin_percent),
            2
        );
    }

    /** Total nominal termin yang sudah dibuat. */
    public function allocatedTerminTotal(): int
    {
        if (! $this->relationLoaded('terminInvoices')) {
            return (int) $this->terminInvoices()->sum('total');
        }

        return (int) $this->terminInvoices->sum('total');
    }

    /** Sisa nilai kontrak yang belum ditagih lewat termin. */
    public function remainingContractValue(): int
    {
        return max(0, (int) $this->total - $this->allocatedTerminTotal());
    }

    /** Semua termin sudah lunas (hanya berlaku bila invoice punya termin). */
    public function allTermsPaid(): bool
    {
        if (! $this->hasTermins()) {
            return false;
        }

        return $this->terminInvoices()->where('status', '!=', InvoiceStatus::Paid)->doesntExist();
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
        $this->ensureCollectible('dilunasi');

        $this->update([
            'status' => InvoiceStatus::Paid,
            'paid_at' => $this->paid_at ?? now(),
        ]);
    }

    /**
     * Invoice induk yang sudah dipecah menjadi termin tidak boleh dilunasi:
     * nilainya sudah ditagih lewat invoice termin. Tanpa guard ini satu
     * kontrak bisa dibayar dua kali (lewat induk DAN lewat termin).
     */
    protected function ensureCollectible(string $action): void
    {
        if ($this->hasTermins()) {
            throw new InvalidInvoiceTransition(
                "Invoice {$this->number} sudah dipecah menjadi termin sehingga tidak dapat {$action}. Lunasi invoice termin-nya."
            );
        }
    }

    /** -> dibatalkan (status final). Invoice lunas tidak bisa dibatalkan. */
    public function cancel(): void
    {
        $this->ensureTransitionAllowed('dibatalkan');

        $this->update(['status' => InvoiceStatus::Cancelled]);
    }

    /**
     * Invoice induk dengan termin TIDAK ikut dihitung sebagai piutang/pemasukan.
     *
     * Nilai kontrak invoice induk sama dengan jumlah terminnya (pecahan 100%),
     * jadi menghitung keduanya akan menagih nilai yang sama dua kali. Laporan
     * & pengingat overdue memakai scope ini.
     */
    public function scopeWithoutTerminParent(Builder $query): Builder
    {
        return $query->whereDoesntHave('terminInvoices');
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
