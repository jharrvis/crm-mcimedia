<?php

namespace App\Domains\Invoicing\Services;

use App\Domains\Invoicing\Enums\InvoiceStatus;
use App\Domains\Invoicing\Models\Invoice;
use App\Domains\Invoicing\Models\RecurringPlan;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Penerbit invoice recurring (F4-11).
 *
 * Satu paket recurring menghasilkan satu invoice per periode penagihan.
 * Pemanggilan berulang pada hari yang sama bersifat idempoten: invoice untuk
 * periode yang sudah terbit dilewati, sehingga command harian tidak
 * menggandakan tagihan. Penjaga terakhirnya adalah indeks unik di level
 * database (`invoices(recurring_plan_id, period_start)`) — tapi tabrakan unik
 * LAIN (mis. `number`) tetap dilempar, bukan dianggap "sudah terbit", supaya
 * tagihan yang gagal tidak hilang tanpa jejak.
 */
class RecurringInvoiceGenerator
{
    /**
     * Terbitkan invoice untuk periode berjalan dari satu paket.
     *
     * Mengembalikan invoice yang baru dibuat, atau null bila periode ini
     * sudah pernah diterbitkan (idempoten) atau paket tidak punya item.
     *
     * @param  bool|null  $autoSend  null = pakai nilai paket (auto_send).
     */
    public function generateForPlan(RecurringPlan $plan, ?bool $autoSend = null): ?Invoice
    {
        return DB::transaction(function () use ($plan, $autoSend) {
            // Kunci baris paket: dua proses paralel untuk paket yang sama
            // akan serialize di sini, sehingga cek idempotensi di bawah valid.
            $plan = RecurringPlan::query()->lockForUpdate()->findOrFail($plan->getKey());

            // Periode dihitung dari paket SETELAH dikunci — kalau dihitung
            // dari instance luar, nilainya bisa basi bila proses lain sudah
            // majukan tanggal tagihan.
            $periodStart = $plan->nextPeriodStart();
            $periodEnd = $plan->nextPeriodEnd();

            // Periode yang belum dimulai tidak ditagih lebih awal. Ini yang
            // membuat generate berulang pada hari yang sama idempoten: setelah
            // periode pertama terbit, `next_invoice_date` sudah maju ke periode
            // BERIKUTNYA yang belum jatuh tempo — tanpa penjaga ini, pemanggilan
            // kedua akan langsung menerbitkan invoice untuk periode depan.
            if ($periodStart->isFuture()) {
                return null;
            }

            if ($plan->invoiceForPeriod($periodStart) !== null) {
                return null;
            }

            // Paket tanpa item tidak menghasilkan invoice (total 0) — lewati
            // tanpa error agar template kosong tidak menagih klien.
            $items = $plan->items()->get();
            if ($items->isEmpty()) {
                return null;
            }

            $issueDate = Carbon::today();

            try {
                $invoice = Invoice::create([
                    'client_id' => $plan->client_id,
                    'number' => $this->numberFor($issueDate),
                    'title' => $plan->title,
                    'issue_date' => $issueDate,
                    'due_date' => $issueDate->copy()->addDays($plan->due_days),
                    'status' => InvoiceStatus::Draft,
                    'recurring_plan_id' => $plan->getKey(),
                    'recurring_cycle' => $plan->cycle,
                    'period_start' => $periodStart,
                    'period_end' => $periodEnd,
                    'notes' => $plan->notes,
                ]);
            } catch (UniqueConstraintViolationException $e) {
                // Tabrakan unique TIDAK selalu berarti "periode sudah terbit".
                // Penanda unik ada di dua kolom: (recurring_plan_id,
                // period_start) — yang memang penjaga idempotensi — dan
                // `number`. Tabrakan nomor berarti kegagalan sungguhan
                // (penomoran bentrok), dan swallow-nya di sini akan membuat
                // tagihan hilang tanpa jejak: tidak ada invoice, periode
                // tidak maju, command melaporkannya sebagai "dilewati".
                //
                // Karena itu periksalah faktanya: invoice untuk periode ini
                // benar-benar sudah ada? Kalau ya, ini duplikat yang wajar
                // (proses paralel menang balapan) → null. Kalau tidak, biarkan
                // exception-nya naik supaya tagihan yang gagal kelihatan.
                if ($plan->invoiceForPeriod($periodStart) !== null) {
                    return null;
                }

                throw $e;
            }

            // Paket yang tertaut satu layanan memakai relasi yang sama dengan
            // invoice gabungan F4-8 (pivot invoice_service).
            if ($plan->service_id) {
                $invoice->services()->attach($plan->service_id);
            }

            foreach ($items as $item) {
                $invoice->items()->create([
                    'description' => $item->description,
                    'quantity' => $item->quantity,
                    'unit_price' => $item->unit_price,
                    'amount' => $item->amount(),
                    'sort_order' => $item->sort_order,
                ]);
            }

            $invoice->recalculateTotals();

            // Majukan penagihan satu siklus ke depan irrespective of status
            // invoice (draf maupun terkirim): periode berikutnya harus punya
            // invoice sendiri.
            $plan->forceFill([
                'next_invoice_date' => $plan->cycle->nextPeriodStart($periodEnd),
                'last_generated_at' => now(),
            ])->save();

            if ($autoSend ?? $plan->auto_send) {
                $invoice->markSent();
            }

            return $invoice;
        });
    }

    /**
     * Nomor invoice untuk generate ini.
     *
     * Dipisah sebagai method agar bisa di-override saat pengujian bentrokan
     * nomor; jalur produksi selalu memakai penomoran InvoiceNumber.
     */
    protected function numberFor(Carbon $issueDate): string
    {
        return InvoiceNumber::next($issueDate);
    }
}