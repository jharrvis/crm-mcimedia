<?php

namespace App\Domains\Security\Http\Controllers;

use App\Domains\Security\Services\NetdataService;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\View\View;

class MonitoringServerController extends Controller
{
    public function __construct(
        private readonly NetdataService $netdataService
    ) {}

    /**
     * Halaman Monitoring Server - menampilkan grafik utilisasi server dari Netdata.
     */
    public function index(): View
    {
        $servers = NetdataService::getServers();

        return view('security.monitoring.index', [
            'servers' => $servers,
        ]);
    }

    /**
     * API endpoint untuk mengambil data metrik time-series (AJAX untuk auto-refresh).
     *
     * @return array<string, array{server_name: string, metrics: array<string, array<array{time: int, value: float}>>>}
     */
    public function metrics(Request $request): array
    {
        // Laravel tidak inject query param ke argumen scalar, baca dari ->query()
        // Clamping: after 300..86400 (5 menit - 24 jam), points 10..300
        $after = (int) $request->query('after', 3600);
        $after = max(300, min(86400, $after));

        $points = (int) $request->query('points', 60);
        $points = max(10, min(300, $points));

        return $this->netdataService->fetchAllMetrics($after, $points);
    }

    /**
     * Health check untuk semua server Netdata.
     *
     * @return array<string, bool>
     */
    public function health(): array
    {
        $servers = NetdataService::getServers();
        $results = [];

        foreach (array_keys($servers) as $key) {
            $results[$key] = $this->netdataService->checkServer($key);
        }

        return $results;
    }
}