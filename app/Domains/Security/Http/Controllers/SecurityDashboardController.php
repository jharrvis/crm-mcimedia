<?php

namespace App\Domains\Security\Http\Controllers;

use App\Domains\Clients\Models\Client;
use App\Domains\Security\Enums\IncidentSeverity;
use App\Domains\Security\Models\SecurityAction;
use App\Domains\Security\Models\SecurityIncident;
use App\Domains\Security\Models\SecurityReport;
use App\Http\Controllers\Controller;
use Illuminate\View\View;

/**
 * Dashboard ringkas keamanan per klien (F3-3): insiden terbuka per severity,
 * jumlah tindakan bulan berjalan, jumlah laporan, dan status tautan publik.
 */
class SecurityDashboardController extends Controller
{
    public function index(): View
    {
        // Agregat dihitung untuk SELURUH klien di level DB (bukan per halaman)
        // supaya angka ringkasan tetap global walau tabel dipaginasi.
        // Insiden terbuka per klien per severity: [client_id => [severity => total]].
        $openByClient = [];
        SecurityIncident::query()
            ->open()
            ->selectRaw('client_id, severity, count(*) as total')
            ->groupBy('client_id', 'severity')
            ->get()
            ->each(function ($row) use (&$openByClient) {
                $openByClient[$row->client_id][$row->severity->value] = (int) $row->total;
            });

        $actionsThisMonth = SecurityAction::query()
            ->where('acted_at', '>=', now()->startOfMonth()->toDateString())
            ->selectRaw('client_id, count(*) as total')
            ->groupBy('client_id')
            ->pluck('total', 'client_id');

        $reportCounts = SecurityReport::query()
            ->selectRaw('client_id, count(*) as total')
            ->groupBy('client_id')
            ->pluck('total', 'client_id');

        return view('security.index', [
            'clients' => Client::orderBy('name')
                ->paginate(15)
                ->withQueryString(),
            'severities' => IncidentSeverity::cases(),
            'openByClient' => $openByClient,
            'actionsThisMonth' => $actionsThisMonth,
            'reportCounts' => $reportCounts,
        ]);
    }
}
