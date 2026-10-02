<?php

namespace App\Domains\Reports\Http\Controllers;

use App\Domains\Invoicing\Enums\InvoiceStatus;
use App\Domains\Invoicing\Models\Invoice;
use App\Domains\Invoicing\Models\Payment;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Laporan pemasukan & piutang (F2-7).
 *
 * Agregasi dilakukan di sini (tanpa paket chart/JS): tiga tabel Blade —
 * pemasukan 12 bulan terakhir, invoice belum lunas, dan ringkasan per klien.
 */
class ReportController
{
    /** Nama bulan Indonesia (indeks 1..12). */
    private const MONTHS = [
        1 => 'Jan', 2 => 'Feb', 3 => 'Mar', 4 => 'Apr', 5 => 'Mei', 6 => 'Jun',
        7 => 'Jul', 8 => 'Agu', 9 => 'Sep', 10 => 'Okt', 11 => 'Nov', 12 => 'Des',
    ];

    public function __invoke(Request $request)
    {
        return view('reports.index', [
            'monthlyIncome' => $this->monthlyIncome(),
            'unpaidInvoices' => $this->unpaidInvoices(),
            'clientSummaries' => $this->clientSummaries(),
        ]);
    }

    /**
     * Pemasukan per bulan (12 bulan terakhir, termasuk bulan berjalan) dari
     * pembayaran terkonfirmasi berdasarkan `paid_at`.
     *
     * @return array<int, array{key: string, label: string, total: int, is_current: bool}>
     */
    private function monthlyIncome(): array
    {
        $today = Carbon::today();
        $start = $today->copy()->startOfMonth()->subMonths(11);

        $months = [];
        for ($i = 0; $i < 12; $i++) {
            $month = $start->copy()->addMonths($i);
            $months[$month->format('Y-m')] = [
                'key' => $month->format('Y-m'),
                'label' => self::MONTHS[(int) $month->format('n')].' '.$month->format('Y'),
                'total' => 0,
                'is_current' => $month->isSameMonth($today),
            ];
        }

        Payment::query()
            ->where('status', 'confirmed')
            ->where('paid_at', '>=', $start)
            ->get(['amount', 'paid_at'])
            ->each(function (Payment $payment) use (&$months) {
                $key = $payment->paid_at->format('Y-m');

                if (isset($months[$key])) {
                    $months[$key]['total'] += (int) $payment->amount;
                }
            });

        return array_values($months);
    }

    /**
     * Invoice belum lunas (scope F2-1), jatuh tempo terlama lebih dulu.
     * Setiap baris dilengkapi penanda keterlambatan + umur (hari).
     *
     * Invoice induk termin (F4-10) dikecualikan: piutangnya ada di invoice
     * termin-nya, jadi ikut menghitungnya akan dobel.
     *
     * @return Collection<int, array{invoice: Invoice, is_overdue: bool, days_late: int}>
     */
    private function unpaidInvoices()
    {
        $today = Carbon::today();

        return Invoice::query()
            ->unpaid()
            ->withoutTerminParent()
            ->with('client:id,name')
            ->orderBy('due_date')
            ->orderBy('id')
            ->get()
            ->map(function (Invoice $invoice) use ($today) {
                $isOverdue = $invoice->status === InvoiceStatus::Overdue
                    || ($invoice->status === InvoiceStatus::Sent && $invoice->due_date->isBefore($today));

                // Umur keterlambatan hanya dihitung bila jatuh tempo sudah lewat.
                $daysLate = $invoice->due_date->isBefore($today)
                    ? (int) abs($today->diffInDays($invoice->due_date, false))
                    : 0;

                return [
                    'invoice' => $invoice,
                    'is_overdue' => $isOverdue,
                    'days_late' => $daysLate,
                ];
            });
    }

    /**
     * Ringkasan per klien yang punya invoice (di luar yang dibatalkan):
     * total nilai invoice, total lunas, dan total outstanding.
     * Urut outstanding terbesar. Agregasi di level DB (portable SQLite/MySQL).
     *
     * Invoice induk termin (F4-10) dikecualikan karena nilainya sudah tercermin
     * di invoice termin — menghitungnya akan membuat total per klien dobel.
     *
     * @return \Illuminate\Database\Eloquent\Collection<int, Invoice>
     */
    private function clientSummaries()
    {
        $paid = InvoiceStatus::Paid->value;
        $sent = InvoiceStatus::Sent->value;
        $overdue = InvoiceStatus::Overdue->value;
        $cancelled = InvoiceStatus::Cancelled->value;

        return Invoice::query()
            ->where('status', '!=', $cancelled)
            ->withoutTerminParent()
            ->groupBy('client_id')
            ->selectRaw(
                'client_id,'
                .' SUM(total) as total_amount,'
                ." SUM(CASE WHEN status = '{$paid}' THEN total ELSE 0 END) as paid_amount,"
                ." SUM(CASE WHEN status IN ('{$sent}', '{$overdue}') THEN total ELSE 0 END) as outstanding_amount"
            )
            ->with('client:id,name')
            ->orderByDesc('outstanding_amount')
            ->get();
    }
}
