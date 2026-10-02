<?php

namespace App\Domains\Services\Models;

use App\Domains\Clients\Models\Client;
use App\Domains\Core\Traits\LogsActivity;
use App\Domains\Invoicing\Models\Invoice;
use App\Domains\Services\Enums\ServiceCycle;
use App\Domains\Services\Enums\ServiceStatus;
use App\Domains\Services\Enums\ServiceType;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

class Service extends Model
{
    use HasFactory;
    use LogsActivity;

    protected $fillable = [
        'client_id', 'parent_id', 'type', 'name', 'reference', 'start_date', 'end_date',
        'price', 'cycle', 'status', 'reminder_enabled', 'notes',
    ];

    protected function casts(): array
    {
        return [
            'type' => ServiceType::class,
            'cycle' => ServiceCycle::class,
            'status' => ServiceStatus::class,
            'start_date' => 'date',
            'end_date' => 'date',
            'price' => 'integer',
            'reminder_enabled' => 'boolean',
        ];
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    /** Domain induk (F4-9). Null bila layanan ini bukan subdomain. */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    /** Subdomain/layanan anak yang dikelompokkan di bawah layanan ini (F4-9). */
    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id');
    }

    public function invoices(): HasMany
    {
        return $this->hasMany(Invoice::class);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', ServiceStatus::Active);
    }

    /**
     * Subdomain: layanan yang punya domain induk.
     *
     * Nama scope-nya `subdomains` (bukan `children`) supaya tidak bentrok
     * dengan relasi instance `children()` — `Service::children()` akan
     * memanggil relasi, bukan scope.
     */
    public function scopeSubdomains(Builder $query): Builder
    {
        return $query->whereNotNull('parent_id');
    }

    /** Layanan tingkat atas: tidak punya domain induk. */
    public function scopeRoots(Builder $query): Builder
    {
        return $query->whereNull('parent_id');
    }

    /**
     * Urutkan agar setiap subdomain tampil tepat di bawah domain induknya (F4-9).
     *
     * Sort key = `COALESCE(parent_id, id)`: layanan tingkat atas memakai id-nya
     * sendiri, subdomain memakai id induknya — sehingga semua anggota satu
     * pohon berbagi key dan tampil berurutan (induk dulu, lalu subdomain-nya
     * berdasarkan nama). Cukup `ORDER BY` pada tabel sendiri, tanpa self-join
     * maupun recursive CTE, jadi portabel di SQLite/MySQL.
     *
     * Dipakai di daftar layanan dan halaman detail klien supaya admin langsung
     * melihat grup domain, bukan domain di atas dan subdomain di halaman bawah.
     *
     * Catatan: paginasi bisa memotong satu grup (induk di halaman 1, subdomain
     * di halaman 2). Label "Subdomain dari …" di tiap baris anak menutup
     * kebutuhan ini tanpa query rekursif.
     */
    public function scopeGroupedByParent(Builder $query): Builder
    {
        $sortKey = 'COALESCE(services.parent_id, services.id)';

        return $query
            ->orderByRaw($sortKey)
            // Induk selalu di atas subdomain-nya dalam satu grup.
            ->orderByRaw('CASE WHEN services.parent_id IS NULL THEN 0 ELSE 1 END')
            ->orderBy('services.name');
    }

    public function isChild(): bool
    {
        return $this->parent_id !== null;
    }

    /**
     * Filter daftar layanan (dipakai `services.index`).
     *
     * Kolom tetap di-prefix `services.` agar unambiguous seandainya scope ini
     * nanti digabung dengan join.
     *
     * @param  string|null  $q  pencarian nama/referensi
     * @param  string|null  $status  'all' berarti tanpa filter status
     */
    public function scopeFiltered(Builder $query, ?string $q = null, ?int $clientId = null, ?string $type = null, ?string $status = 'active'): Builder
    {
        return $query
            ->when(filled($q), fn ($w) => $w->where(function ($w) use ($q) {
                $w->where('services.name', 'like', "%{$q}%")
                    ->orWhere('services.reference', 'like', "%{$q}%");
            }))
            ->when($clientId, fn ($w) => $w->where('services.client_id', $clientId))
            ->when(filled($type), fn ($w) => $w->where('services.type', $type))
            ->when(filled($status) && $status !== 'all', fn ($w) => $w->where('services.status', $status));
    }

    /**
     * Domain/layanan kandidat jadi induk: tingkat atas, jenis sama, dan punya
     * label yang mengandung kata kunci pencarian (dipakai dropdown form F4-9).
     */
    public function scopeParentCandidates(Builder $query, string $term = '', ?int $clientId = null): Builder
    {
        return $query->whereNull('parent_id')
            ->where('type', ServiceType::Domain)
            ->when($clientId, fn ($q) => $q->where('client_id', $clientId))
            ->when($term !== '', fn ($q) => $q->where(function ($w) use ($term) {
                $w->where('name', 'like', "%{$term}%")
                    ->orWhere('reference', 'like', "%{$term}%");
            }))
            ->orderBy('name');
    }

    /**
     * Tebakan domain induk dari nama subdomain (F4-9).
     *
     * `www.mulkani.co.id` -> `mulkani.co.id`: ambil label kandidat (nama atau
     * reference) yang menjadi suffix dari nama layanan. Dipakai untuk
     * pre-select di form: admin tetap memilih sendiri, tapi pilihan paling
     * masuk akal sudah terpilih.
     *
     * Kandidat dibatasi ke klien yang sama (bila diketahui) dan hanya layanan
     * tingkat atas — persis isi dropdown `scopeParentCandidates()`. Tanpa
     * pembatasan ini tebakan bisa memilih domain milik klien lain (khususnya
     * ketika dua klien sama-sama memakai `mulkani.co.id`), yang nanti ditolak
     * validasi meski nilainya tampak benar.
     */
    public static function suggestParentId(?string $name, ?string $reference = null, ?int $clientId = null): ?int
    {
        $host = self::normalizeHost($reference ?: $name);

        if ($host === null || ! str_contains($host, '.')) {
            return null;
        }

        $candidates = self::query()
            ->where('type', ServiceType::Domain)
            ->whereNull('parent_id')
            ->when($clientId, fn ($q) => $q->where('client_id', $clientId))
            ->get(['id', 'name', 'reference']);

        $best = null;
        $bestLength = 0;

        foreach ($candidates as $candidate) {
            foreach ([$candidate->reference, $candidate->name] as $label) {
                $host2 = self::normalizeHost($label);

                // Cocok bila kandidat adalah suffix dengan pemisah label (".").
                if ($host2 === null || strlen($host2) >= strlen($host)) {
                    continue;
                }

                if (str_ends_with($host, ".{$host2}") && strlen($host2) > $bestLength) {
                    $best = $candidate->id;
                    $bestLength = strlen($host2);
                }
            }
        }

        return $best;
    }

    /** Normalisasi input domain: lowercase, tanpa skema/path/port/dot akhir. */
    public static function normalizeHost(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $host = mb_strtolower(trim($value));

        if ($host === '') {
            return null;
        }

        $host = preg_replace('#^[a-z][a-z0-9+.-]*://#', '', $host) ?? $host;
        $host = explode('/', $host)[0];
        $host = explode(':', $host)[0];
        $host = rtrim($host, '.');

        return $host === '' ? null : $host;
    }

    /** Layanan aktif, pengingat on, berakhir dalam N hari ke depan (belum lewat). */
    public function scopeExpiringSoon(Builder $query, int $days = 30): Builder
    {
        return $query->where('status', ServiceStatus::Active)
            ->where('reminder_enabled', true)
            ->whereNotNull('end_date')
            ->whereDate('end_date', '>=', Carbon::today())
            ->whereDate('end_date', '<=', Carbon::today()->addDays($days));
    }

    /** Layanan aktif yang tanggal berakhirnya sudah lewat. */
    public function scopeOverdue(Builder $query): Builder
    {
        return $query->where('status', ServiceStatus::Active)
            ->whereNotNull('end_date')
            ->whereDate('end_date', '<', Carbon::today());
    }

    /** Sisa hari hingga berakhir; negatif bila sudah lewat; null bila tanpa tanggal. */
    public function daysUntilEnd(): ?int
    {
        if (! $this->end_date) {
            return null;
        }

        return Carbon::today()->diffInDays($this->end_date, false);
    }

    public function isOverdue(): bool
    {
        return $this->status === ServiceStatus::Active
            && $this->end_date !== null
            && $this->end_date->isPast() && ! $this->end_date->isToday();
    }

    public function activityLabel(): string
    {
        return "layanan {$this->name}";
    }
}
