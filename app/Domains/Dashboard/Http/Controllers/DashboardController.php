<?php

namespace App\Domains\Dashboard\Http\Controllers;

use App\Domains\Clients\Models\Client;
use App\Domains\Invoicing\Enums\InvoiceStatus;
use App\Domains\Invoicing\Models\Invoice;
use App\Domains\Invoicing\Models\Payment;
use App\Domains\Projects\Enums\ProjectStatus;
use App\Domains\Projects\Models\Project;
use App\Domains\Services\Models\Service;
use App\Domains\Tasks\Models\Task;
use Illuminate\Http\Request;

class DashboardController
{
    public function __invoke(Request $request)
    {
        return view('dashboard.index', [
            'stats' => [
                'clients' => Client::active()->count(),
                'services' => Service::active()->count(),
                'projects' => Project::running()->count(),
                'tasks' => Task::open()->count(),
            ],
            'expiringServices' => Service::expiringSoon(30)->with('client')->orderBy('end_date')->limit(10)->get(),
            'overdueServices' => Service::overdue()->with('client')->orderBy('end_date')->limit(10)->get(),
            'runningProjects' => Project::running()->with('client')->orderBy('deadline')->limit(8)->get(),
            'urgentTasks' => Task::urgent()->with(['client', 'project'])->orderBy('due_date')->limit(10)->get(),
            'statuses' => ProjectStatus::cases(),
            // Data chart (ponytail: periode/basis belum bisa dikonfigurasi user — tambah filter saat ada permintaan).
            'revenueChart' => $this->revenueChart(),
            'invoiceStatusChart' => $this->invoiceStatusChart(),
        ]);
    }

    /** Total pembayaran terkonfirmasi per bulan, 12 bulan terakhir. */
    private function revenueChart(): array
    {
        $start = now()->startOfMonth()->subMonths(11);

        $rows = Payment::query()
            ->where('paid_at', '>=', $start)
            ->get(['amount', 'paid_at'])
            ->groupBy(fn ($p) => $p->paid_at->format('Y-m'));

        $labels = [];
        $data = [];
        for ($i = 0; $i < 12; $i++) {
            $month = $start->copy()->addMonths($i);
            $labels[] = $month->isoFormat('MMM YY');
            $data[] = (int) ($rows->get($month->format('Y-m'))?->sum('amount') ?? 0);
        }

        return ['labels' => $labels, 'data' => $data];
    }

    /** Ringkasan jumlah invoice per status. */
    private function invoiceStatusChart(): array
    {
        $counts = Invoice::query()
            ->selectRaw('status, COUNT(*) as c')
            ->groupBy('status')
            ->pluck('c', 'status');

        $labels = [];
        $data = [];
        foreach (InvoiceStatus::cases() as $status) {
            $labels[] = $status->label();
            $data[] = (int) ($counts[$status->value] ?? 0);
        }

        return ['labels' => $labels, 'data' => $data];
    }
}
