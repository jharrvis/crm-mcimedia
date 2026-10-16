<?php

namespace App\Domains\Quotation\Services;

use App\Domains\Invoicing\Enums\InvoiceStatus;
use App\Domains\Invoicing\Models\Invoice;
use App\Domains\Invoicing\Services\InvoiceNumber;
use App\Domains\Quotation\Enums\QuotationStatus;
use App\Domains\Quotation\Exceptions\InvalidQuotationTransition;
use App\Domains\Quotation\Models\Quotation;
use Illuminate\Support\Facades\DB;

/**
 * Konversi quotation (status accepted) menjadi invoice DRAFT (Q-3).
 *
 * Aturan:
 * - Hanya dari status `accepted` dan hanya SEKALI (guard `converted_invoice_id`;
 *   `markConverted()` pada model juga menolak transisi dari status terminal).
 * - Item disalin 1:1 (description, quantity, unit_price, sort_order); tanpa PPN.
 * - Diskon quotation tidak punya kolom di invoice, jadi didistribusikan ke
 *   unit_price tiap item: potongan per unit proporsional (floor) terhadap
 *   unit_price, lalu sisa pembulatan (≤ Σ quantity) disebarkan eksak lewat
 *   bounded-coin DP — cari Δc_i ≥ 0 dengan Σ quantity_i·Δc_i = sisa — sehingga
 *   Σ quantity × unit_price = total quotation (IDR integer).
 *   Bila sisa tidak bisa diwakili (mis. sisa=1 dan semua quantity > 1),
 *   dipilih jumlah terdekat: drift total < quantity terbesar dan arahnya
 *   diutamakan membuat total invoice ≤ total quotation.
 * - due_date invoice = valid_until quotation (menjaga masa berlaku penawaran).
 * - Notes invoice: "Dikonversi dari QUO-YYYYMM-#### (diskon Rp X)".
 */
class QuotationConverter
{
    /** Batas scan kombinasi tambah/kurang antar item (bounded search). */
    private const PAIR_SCAN = 256;

    /** Batas pinjaman unit saat mencari kombinasi tiga item. */
    private const TRIPLE_LOAN = 16;

    /**
     * Konversi penawaran `accepted` menjadi invoice DRAFT.
     *
     * @throws InvalidQuotationTransition
     */
    public static function convert(Quotation $quotation): Invoice
    {
        if ($quotation->status !== QuotationStatus::Accepted) {
            throw new InvalidQuotationTransition(
                "Penawaran {$quotation->number} berstatus {$quotation->status->label()} sehingga tidak dapat dikonversi ke invoice. Hanya penawaran disetujui yang bisa dikonversi."
            );
        }

        if ($quotation->converted_invoice_id !== null) {
            throw new InvalidQuotationTransition(
                "Penawaran {$quotation->number} sudah dikonversi ke invoice sehingga tidak dapat dikonversi ulang."
            );
        }

        return DB::transaction(function () use ($quotation) {
            $quotation->loadMissing(['items']);

            $discount = min(max(0, (int) $quotation->discount), (int) $quotation->subtotal);
            $unitPrices = self::discountedUnitPrices($quotation);

            $invoice = Invoice::create([
                'client_id' => $quotation->client_id,
                'number' => InvoiceNumber::next($quotation->issue_date),
                'title' => $quotation->title,
                'issue_date' => $quotation->issue_date->toDateString(),
                'due_date' => $quotation->valid_until->toDateString(),
                'status' => InvoiceStatus::Draft,
                'subtotal' => 0,
                'total' => 0,
                'notes' => $discount > 0
                    ? "Dikonversi dari {$quotation->number} (diskon Rp ".number_format($discount, 0, ',', '.').')'
                    : "Dikonversi dari {$quotation->number}",
            ]);

            foreach ($unitPrices as $index => $unitPrice) {
                $item = $quotation->items[$index];

                $invoice->items()->create([
                    'description' => $item->description,
                    'quantity' => $item->quantity,
                    'unit_price' => $unitPrice,
                    'amount' => 0,
                    'sort_order' => $item->sort_order ?? $index,
                ]);
            }

            $invoice->recalculateTotals();

            $quotation->markConverted($invoice);

            return $invoice->fresh(['items']);
        });
    }

    /**
     * Unit price tiap item setelah diskon didistribusikan proporsional (Q-3).
     *
     * Urutan hasil = urutan relasi `items` (sort_order, id) — selaras dengan
     * urutan penyalinan item di `convert()`.
     *
     * @return list<int>
     */
    public static function discountedUnitPrices(Quotation $quotation): array
    {
        $prices = [];
        $qty = [];
        $subtotal = 0;

        foreach ($quotation->items as $item) {
            $index = count($prices);
            $prices[$index] = (int) $item->unit_price;
            $qty[$index] = max(1, (int) $item->quantity);
            $subtotal += $qty[$index] * $prices[$index];
        }

        $discount = min(max(0, (int) $quotation->discount), $subtotal);

        if ($discount === 0 || $prices === []) {
            return $prices;
        }

        // 1) Potongan per unit proporsional terhadap unit_price (floor).
        $cuts = [];
        foreach ($prices as $index => $price) {
            $cuts[$index] = intdiv($discount * $price, $subtotal);
        }

        // 2) Seimbangkan sisa pembulatan supaya Σ quantity × cut = discount.
        $cuts = self::balanceRounding($discount, $qty, $prices, $cuts);

        // 3) unit_price baru per item (tidak pernah negatif).
        $result = [];
        foreach ($cuts as $index => $cut) {
            $result[$index] = $prices[$index] - $cut;
        }

        return $result;
    }

    /**
     * Sebar sisa pembulatan diskon ke potongan per unit.
     *
     * Sisa dari pembagian floor selalu >= 0 dan < Σ quantity, jadi pencarian
     * cukup window kecil. Pencarian memakai bounded-coin DP yang mengizinkan
     * peminjaman (Δ negatif) supaya jumlah pencocokan maksimal.
     *
     * @param  list<int>  $qty
     * @param  list<int>  $prices
     * @param  list<int>  $cuts
     * @return list<int>
     */
    private static function balanceRounding(int $discount, array $qty, array $prices, array $cuts): array
    {
        $applied = 0;
        foreach ($qty as $index => $q) {
            $applied += $q * $cuts[$index];
        }

        $residual = $discount - $applied;

        if ($residual <= 0) {
            return $cuts;
        }

        $room = [];
        foreach ($prices as $index => $price) {
            $room[$index] = $price - $cuts[$index]; // ruang menambah potongan
        }

        foreach (self::spreadResidual($residual, $qty, $cuts, $room) as $index => $delta) {
            $cuts[$index] += $delta;
        }

        return $cuts;
    }

    /**
     * Cari perubahan unit per item sehingga Σ quantity × Δ = `target` bila
     * memungkinkan, atau sedekat mungkin. Mengutamakan jumlah pas; bila tidak
     * dengan arah ke atas dulu (potongan lebih besar -> total invoice tidak
     * pernah melebihi total quotation).
     *
     * @param  list<int>  $qty
     * @param  list<int>  $cuts  potongan saat ini (batas bawah Δ)
     * @param  list<int>  $room  sisa harga (batas atas Δ)
     * @return list<int>
     */
    private static function spreadResidual(int $target, array $qty, array $cuts, array $room): array
    {
        $n = count($qty);
        if ($n === 0) {
            return [];
        }

        // Jangkauan pencarian: cukup menutup residual dan satu putaran
        // bolak-balik per item, karena Σ |Δ| × quantity yang berguna tidak
        // melebihi ini.
        $window = $target + 2 * array_sum($qty);
        $low = -$window;
        $high = $target + $window;

        $reach = [0 => array_fill(0, $n, 0)];

        foreach ($qty as $index => $q) {
            $deltaMin = max(-$cuts[$index], intdiv($low, $q));
            $deltaMax = min($room[$index], intdiv($high, $q));

            if ($deltaMin > $deltaMax) {
                continue;
            }

            $next = $reach;

            foreach ($reach as $sum => $deltas) {
                for ($delta = $deltaMin; $delta <= $deltaMax; $delta++) {
                    $candidate = $sum + $delta * $q;

                    if ($candidate < $low || $candidate > $high) {
                        continue;
                    }

                    if (! array_key_exists($candidate, $next)) {
                        $path = $deltas;
                        $path[$index] = $delta;
                        $next[$candidate] = $path;
                    }
                }
            }

            $reach = $next;
        }

        for ($offset = 0; $offset <= $window; $offset++) {
            $probes = $offset === 0 ? [$target] : [$target + $offset, $target - $offset];

            foreach ($probes as $probe) {
                if (isset($reach[$probe])) {
                    return $reach[$probe];
                }
            }
        }

        return array_fill(0, $n, 0);
    }
}
