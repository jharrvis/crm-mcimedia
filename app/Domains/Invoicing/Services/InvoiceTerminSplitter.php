<?php

namespace App\Domains\Invoicing\Services;

use App\Domains\Invoicing\Enums\InvoiceStatus;
use App\Domains\Invoicing\Exceptions\InvalidTerminSplit;
use App\Domains\Invoicing\Models\Invoice;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * F4-10: memecah nilai kontrak (invoice induk) menjadi beberapa invoice termin
 * dengan jatuh tempo masing-masing (mis. 30%/30%/40%).
 *
 * Setiap termin dibuat sebagai invoice UTUH — punya nomor, status, pembayaran,
 * PDF, dan tautan publik sendiri — hanya ditautkan ke induknya lewat
 * invoices.parent_invoice_id. Dengan begitu seluruh alur yang sudah ada
 * (kirim email/WhatsApp, magic link, pengingat overdue, PDF) langsung berlaku
 * untuk termin tanpa perlu jalur khusus.
 *
 * Invoice induk tetap ada sebagai dokumen kontrak: nilainya tidak diubah dan
 * tidak ikut dihitung sebagai piutang (lihat Invoice::scopeWithoutTerminParent)
 * supaya nilai kontrak tidak terhitung dua kali di laporan.
 */
class InvoiceTerminSplitter
{
    /** Jumlah termin maksimum dalam satu pecahan. */
    public const MAX_TERMS = 24;

    /**
     * Batas atas nilai kontrak (IDR) yang boleh dipecah menjadi termin.
     *
     * Division memakai aritmetika integer: nominal * basis-point harus muat di
     * integer 64-bit (maks ~9,2 x 10^18). Batas ini jauh di bawah plafon itu,
     * jadi hasil pembagian selalu eksak. Nilai kontrak di atas batas ini
     * ditolak dengan pesan jelas, bukan diam-diam menghasilkan nominal salah.
     */
    public const MAX_CONTRACT_TOTAL = 900000000000; // Rp900 miliar

    /** Toleransi pembulatan persentase (basis point). */
    private const PERCENT_EPSILON = 0.0001;

    /**
     * Pecah $parent menjadi beberapa invoice termin.
     *
     * @param  array<int, array{percent: mixed, due_date: mixed}>  $terms  Minimal 2 termin.
     * @return Collection<int, Invoice>
     *
     * @throws InvalidTerminSplit
     */
    public function split(Invoice $parent, array $terms): Collection
    {
        $this->guardParent($parent);

        // allocate() memvalidasi seluruh input (jumlah termin, persentase,
        // tanggal jatuh tempo, nominal) dan melempar InvalidTerminSplit bila
        // tidak valid — jadi split() tidak perlu mengulang validasi tersebut.
        $shares = $this->allocate((int) $parent->total, $terms, $parent->issue_date);

        $this->guardNonZeroTerms($parent, $shares);

        // Semua termin dibuat dalam satu transaksi supaya tidak pernah ada
        // kondisi setengah jadi: sebagian termin sudah tersimpan lalu request
        // gagal (mis. nomor invoice bentrok) sehingga nilai kontrak tercecer.
        return DB::transaction(function () use ($parent, $shares): Collection {
            $created = new Collection;

            foreach ($shares as $index => $share) {
                $termin = Invoice::create([
                    'client_id' => $parent->client_id,
                    'parent_invoice_id' => $parent->id,
                    'termin_percent' => $share['percent'],
                    'number' => InvoiceNumber::next($parent->issue_date),
                    'title' => $this->terminTitle($parent, $index + 1, count($shares)),
                    'issue_date' => $parent->issue_date,
                    'due_date' => $share['due_date'],
                    'status' => InvoiceStatus::Draft,
                    // Termin mewarisi catatan kontrak: yang ditagih termin
                    // memang bagian dari nilai kontrak yang sama.
                    'notes' => $parent->notes,
                ]);

                // Termin mewarisi layanan yang dicakup kontrak.
                $termin->services()->sync(
                    $parent->services()->pluck('services.id')->all()
                );

                // Satu item berisi nilai termin. Nominalnya berasal dari
                // pembagian nilai kontrak, bukan dari item induk per item,
                // supaya pembulatan rupiah tidak pernah menggandakan atau
                // memotong nilai kontrak.
                $termin->items()->create([
                    'description' => sprintf(
                        'Termin %d dari invoice kontrak %s (%s)',
                        $index + 1,
                        $parent->number,
                        $this->formatPercent($share['percent'])
                    ),
                    'quantity' => 1,
                    'unit_price' => $share['amount'],
                    'amount' => $share['amount'],
                    'sort_order' => 0,
                ]);

                $termin->recalculateTotals();

                $created->push($termin);
            }

            return $created;
        });
    }

    // ---------- validasi ----------

    /** Invoice induk harus punya nilai kontrak dan belum pernah dipecah. */
    private function guardParent(Invoice $parent): void
    {
        // Termin tidak boleh dipecah lagi: struktur datanya dirancang satu
        // tingkat (induk -> termin). Tanpa guard ini invoice bisa dipecah
        // bertingkat tanpa batas dan laporan ikut kacau.
        if ($parent->isTermin()) {
            throw new InvalidTerminSplit(
                "Invoice {$parent->number} sudah merupakan termin sehingga tidak dapat dipecah lagi."
            );
        }

        if ($parent->hasTermins()) {
            throw new InvalidTerminSplit(
                "Invoice {$parent->number} sudah memiliki termin sehingga nilai kontrak tidak dapat dipecah lagi."
            );
        }

        if ($parent->isTerminal()) {
            throw new InvalidTerminSplit(
                "Invoice {$parent->number} berstatus {$parent->status->label()} sehingga tidak dapat dipecah menjadi termin."
            );
        }

        if ((int) $parent->total <= 0) {
            throw new InvalidTerminSplit(
                "Invoice {$parent->number} belum punya nilai kontrak. Tambahkan item bernilai lebih dulu sebelum dipecah menjadi termin."
            );
        }

        // Di atas batas ini pembagian integer tidak lagi eksak.
        if ((int) $parent->total > self::MAX_CONTRACT_TOTAL) {
            throw new InvalidTerminSplit(
                'Nilai kontrak melebihi batas yang dapat dipecah menjadi termin ('.rupiah(self::MAX_CONTRACT_TOTAL).').'
            );
        }
    }

    /**
     * Termin tidak boleh bernilai nol: nilai kontrak terlalu kecil untuk
     * dibagi sesuai persentase yang diminta.
     *
     * @param  array<int, array{percent: float, amount: int, due_date: Carbon}>  $shares
     */
    private function guardNonZeroTerms(Invoice $parent, array $shares): void
    {
        foreach ($shares as $index => $share) {
            if ($share['amount'] < 1) {
                throw new InvalidTerminSplit(sprintf(
                    'Termin %d bernilai nol. Nilai kontrak %s terlalu kecil untuk dibagi %d termin — kurangi jumlah termin.',
                    $index + 1,
                    rupiah((int) $parent->total),
                    count($shares)
                ));
            }
        }
    }

    // ---------- pembagian nilai kontrak ----------

    /**
     * Validasi input mentah lalu bagi nilai kontrak ke tiap termin dengan
     * largest-remainder method.
     *
     * Tiap termin mendapat pembagian floorsemua; sisa rupiah akibat
     * pembulatan diberikan ke termin dengan pecahan desimal terbesar (tie-break
     * sesuai urutan). Jumlah nominal termin dijamin PERSIS sama dengan nilai
     * kontrak, tidak kurang dan tidak lebih.
     *
     * @param  array<int, array{percent: mixed, due_date: mixed}>  $terms
     * @param  Carbon|\DateTimeInterface|string  $issueDate  Tanggal terbit kontrak.
     * @return array<int, array{percent: float, amount: int, due_date: Carbon}>
     *
     * @throws InvalidTerminSplit
     */
    public function allocate(int $contractTotal, array $terms, mixed $issueDate = null): array
    {
        $normalized = $this->normalizeTerms($terms, $issueDate);

        // Aritmetika SELURUHNYA integer. Versi float sebelumnya
        // ($contractTotal * $percent / 100) kehilangan presisi di atas 2^53,
        // sehingga floor() bisa meleset dan sisa rupiah jadi negatif — yang
        // membuat jumlah termin TIDAK sama dengan nilai kontrak.
        //
        // Persentase dikonversi ke basis point (1% = 100 bp) supaya pembagian
        // bisa pakai intdiv() yang eksak untuk rentang nilai kontrak yang
        // dipakai CRM ini (IDR).
        $amounts = [];
        $allocated = 0;

        foreach ($normalized as $index => $share) {
            $basisPoints = (int) round($share['percent'] * 100);
            // intdiv() hanya menerima int; nilai kontrak dari DB sudah int,
            // tapi perkalian bisa meluap jadi float pada total ekstrem.
            $amounts[$index] = intdiv((int) ($contractTotal * $basisPoints), 10000);
            $allocated += $amounts[$index];
        }

        $remainder = $contractTotal - $allocated;

        // Pecahan desimal tiap termin = bagian rupiah yang hilang karena
        // floor. Termin dengan pecahan terbesar menerima sisa rupiah.
        // Pada rentang normal (< 2^53) float aman di sini karena nilainya hanya
        // membandingkan urutan, bukan jumlah uang.
        $order = [];
        foreach ($normalized as $index => $share) {
            $basisPoints = (int) round($share['percent'] * 100);
            $numerator = (int) ($contractTotal * $basisPoints);
            $order[] = [
                'index' => $index,
                'fraction' => $numerator % 10000,
            ];
        }

        // Tie-break: remainder desc, lalu index asc — hasil deterministik.
        usort(
            $order,
            fn (array $a, array $b): int => ($b['fraction'] <=> $a['fraction']) ?: ($a['index'] <=> $b['index'])
        );

        foreach ($order as $position => $entry) {
            if ($position >= $remainder) {
                break;
            }

            $amounts[$entry['index']]++;
        }

        $result = [];
        foreach ($normalized as $index => $share) {
            $result[$index] = [
                'percent' => $share['percent'],
                'amount' => $amounts[$index],
                'due_date' => $share['due_date'],
            ];
        }

        return $result;
    }

    /**
     * Bersihkan input mentah: persentase > 0, total tepat 100%, tanggal jatuh
     * tempo terisi dan tidak mendahului tanggal terbit kontrak.
     *
     * @param  array<int, array{percent: mixed, due_date: mixed}>  $terms
     * @param  mixed  $issueDate  Tanggal terbit kontrak.
     * @return array<int, array{percent: float, due_date: Carbon}>
     *
     * @throws InvalidTerminSplit
     */
    private function normalizeTerms(array $terms, mixed $issueDate = null): array
    {
        if ($terms === []) {
            throw new InvalidTerminSplit('Pilih minimal satu termin.');
        }

        $count = count($terms);

        // Minimal 2 termin — memecah jadi satu termin bukan pemecahan.
        if ($count < 2) {
            throw new InvalidTerminSplit('Pecah menjadi minimal dua termin.');
        }

        if ($count > self::MAX_TERMS) {
            throw new InvalidTerminSplit('Jumlah termin melebihi batas '.self::MAX_TERMS.' termin.');
        }

        $normalized = [];
        $index = 0;

        foreach ($terms as $term) {
            $index++;

            $percent = (float) ($term['percent'] ?? 0);

            if ($percent <= 0 || $percent > 100) {
                throw new InvalidTerminSplit(sprintf(
                    'Persentase termin %d harus lebih dari 0 dan tidak melebihi 100.',
                    $index
                ));
            }

            $normalized[] = [
                'percent' => round($percent, 2),
                'due_date' => $this->parseDueDate($term['due_date'] ?? null, $issueDate, $index),
            ];
        }

        $sum = array_sum(array_column($normalized, 'percent'));

        if (abs($sum - 100.0) > self::PERCENT_EPSILON) {
            throw new InvalidTerminSplit(sprintf(
                'Total persentase termin harus tepat 100%% (kini %s%%).',
                rtrim(rtrim(number_format($sum, 2, '.', ''), '0'), '.')
            ));
        }

        return $normalized;
    }

    /** Tanggal jatuh tempo termin wajib ada dan tidak boleh mendahului tanggal terbit kontrak. */
    private function parseDueDate(mixed $value, mixed $issueDate, int $index): Carbon
    {
        if (blank($value)) {
            throw new InvalidTerminSplit("Tanggal jatuh tempo termin {$index} wajib diisi.");
        }

        try {
            $date = Carbon::parse($value)->startOfDay();
        } catch (Throwable) {
            throw new InvalidTerminSplit("Tanggal jatuh tempo termin {$index} tidak valid.");
        }

        // Termin tidak boleh jatuh tempo sebelum kontrak mulai berlaku.
        if ($issueDate !== null) {
            try {
                $issued = Carbon::parse($issueDate)->startOfDay();
            } catch (Throwable) {
                $issued = null;
            }

            if ($issued !== null && $date->lt($issued)) {
                throw new InvalidTerminSplit(
                    "Tanggal jatuh tempo termin {$index} tidak boleh sebelum tanggal terbit invoice kontrak."
                );
            }
        }

        return $date;
    }

    private function terminTitle(Invoice $parent, int $ordinal, int $count): string
    {
        $base = $parent->title ?: "Kontrak {$parent->number}";

        return "{$base} — Termin {$ordinal} dari {$count}";
    }

    private function formatPercent(float $percent): string
    {
        return rtrim(rtrim(number_format($percent, 2, '.', ''), '0'), '.').'%';
    }
}
