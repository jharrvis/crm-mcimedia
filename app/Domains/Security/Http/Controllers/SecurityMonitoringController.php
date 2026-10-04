<?php

namespace App\Domains\Security\Http\Controllers;

use App\Domains\Clients\Models\Client;
use App\Domains\Security\Enums\IncidentSeverity;
use App\Domains\Security\Models\MonitoringEvent;
use App\Domains\Security\Models\SecurityIncident;
use App\Domains\Security\Models\SslCertificate;
use App\Domains\Security\Models\TrafficData;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\View\View;

class SecurityMonitoringController extends Controller
{
    /**
     * Halaman Security Monitoring Dashboard.
     */
    public function index(): View
    {
        return view('security.monitoring.dashboard');
    }

    /**
     * API: Ringkasan status (widget angka).
     *
     * @return JsonResponse<array{
     *     open_incidents: array{critical: int, high: int, medium: int, low: int, info: int},
     *     waf_blocked_today: int,
     *     failed_logins_24h: int,
     *     ssl_expiring_14d: int
     * }>
     */
    public function summary(): JsonResponse
    {
        // Insiden open per severity
        $openIncidents = SecurityIncident::query()
            ->open()
            ->selectRaw('severity, count(*) as total')
            ->groupBy('severity')
            ->get()
            ->mapWithKeys(function ($row) {
                $severityValue = $row->severity instanceof IncidentSeverity ? $row->severity->value : $row->severity;
                return [$severityValue => (int) $row->total];
            });

        $severityCounts = [];
        foreach (IncidentSeverity::cases() as $severity) {
            $severityCounts[$severity->value] = (int) ($openIncidents[$severity->value] ?? 0);
        }

        // WAF blocked requests hari ini dari monitoring_events
        $wafBlockedToday = MonitoringEvent::query()
            ->today()
            ->type('waf_block')
            ->count();

        // Failed logins 24 jam terakhir
        $failedLogins24h = MonitoringEvent::query()
            ->recent(24)
            ->type('brute_force')
            ->count();

        // SSL akan expired < 14 hari
        $sslExpiring14d = SslCertificate::query()
            ->expiringWithin(14)
            ->count();

        return response()->json([
            'open_incidents' => $severityCounts,
            'waf_blocked_today' => $wafBlockedToday,
            'failed_logins_24h' => $failedLogins24h,
            'ssl_expiring_14d' => $sslExpiring14d,
        ]);
    }

    /**
     * API: Grafik traffic requests/detik per site.
     *
     * @return JsonResponse<array{
     *     data: array<string, array<array{time: int, value: float}>>,
     *     baseline: array<string, float>,
     *     anomalies: array<int, array{site: string, time: int, value: float, baseline: float}>
     * }>
     */
    public function traffic(Request $request): JsonResponse
    {
        $range = $request->string('range', '24h')->value();
        $after = match ($range) {
            '1h' => 3600,
            '24h' => 86400,
            '7d' => 604800,
            default => 86400,
        };

        // Get traffic data from DB with caching
        $cacheKey = "security.traffic.{$range}";
        $data = Cache::remember($cacheKey, 60, function () use ($after) {
            return $this->getTrafficData($after);
        });

        $baseline = $this->calculateBaseline($data);
        $anomalies = $this->detectAnomalies($data, $baseline);

        return response()->json([
            'data' => $data,
            'baseline' => $baseline,
            'anomalies' => $anomalies,
        ]);
    }

    /**
     * API: Log serangan terbaru (realtime via polling).
     *
     * @return JsonResponse<array{
     *     data: array<int, array{time: string, ip: string, type: string, target_url: string, country: string, severity: string}>,
     *     current_page: int,
     *     last_page: int,
     *     total: int
     * }>
     */
    public function attacks(Request $request): JsonResponse
    {
        $page = $request->integer('page', 1);
        $perPage = $request->integer('per_page', 25);

        $query = MonitoringEvent::query()
            ->recent(168) // 7 hari
            ->with('client')
            ->orderByDesc('occurred_at');

        $paginator = $query->paginate($perPage, ['*'], 'page', $page);

        $data = collect($paginator->items())->map(function ($event) {
            return [
                'id' => $event->id,
                'time' => $event->occurred_at?->toIso8601String(),
                'ip' => $event->ip,
                'type' => $event->type,
                'target_url' => $event->target_url,
                'country' => $event->country,
                'severity' => $event->severity instanceof IncidentSeverity ? $event->severity->value : $event->severity,
                'client' => $event->client ? ['id' => $event->client->id, 'name' => $event->client->name] : null,
            ];
        })->toArray();

        return response()->json([
            'data' => $data,
            'current_page' => $paginator->currentPage(),
            'last_page' => $paginator->lastPage(),
            'total' => $paginator->total(),
            'per_page' => $paginator->perPage(),
        ]);
    }

    /**
     * API: Status layanan (uptime + SSL).
     *
     * @return JsonResponse<array{
     *     uptime: array<int, array{site: string, status: string, uptime_pct: float, last_check: string|null}>,
     *     ssl: array<int, array{domain: string, status: string, expires_at: string|null, days_left: int|null}>
     * }>
     */
    public function services(): JsonResponse
    {
        // Uptime data dari Uptime Kuma (via cache atau table)
        $uptime = $this->getUptimeData();

        // SSL data dari database
        $ssl = SslCertificate::query()
            ->with('client')
            ->orderBy('expires_at')
            ->get()
            ->map(function ($cert) {
                return [
                    'domain' => $cert->domain,
                    'status' => $cert->status,
                    'expires_at' => $cert->expires_at?->toIso8601String(),
                    'days_left' => $cert->daysUntilExpiry(),
                    'client' => $cert->client ? ['id' => $cert->client->id, 'name' => $cert->client->name] : null,
                ];
            })
            ->toArray();

        return response()->json([
            'uptime' => $uptime,
            'ssl' => $ssl,
        ]);
    }

    // ===== Private helper methods =====

    /**
     * @return array<string, array<array{time: int, value: float}>>
     */
    private function getTrafficData(int $after): array
    {
        $trafficData = TrafficData::query()
            ->inRange($after)
            ->orderBy('timestamp')
            ->get();

        $grouped = [];
        foreach ($trafficData as $point) {
            $site = $point->site;
            if (! isset($grouped[$site])) {
                $grouped[$site] = [];
            }
            $grouped[$site][] = [
                'time' => $point->timestamp->timestamp,
                'value' => (float) $point->requests_per_second,
            ];
        }

        return $grouped;
    }

    /**
     * @return array<string, float>
     */
    private function calculateBaseline(array $data): array
    {
        $baseline = [];
        foreach ($data as $site => $points) {
            if (empty($points)) {
                $baseline[$site] = 0;
                continue;
            }
            $values = array_column($points, 'value');
            $baseline[$site] = array_sum($values) / count($values);
        }
        return $baseline;
    }

    /**
     * @param array<string, array<array{time: int, value: float}>> $data
     * @param array<string, float> $baseline
     * @return array<int, array{site: string, time: int, value: float, baseline: float}>
     */
    private function detectAnomalies(array $data, array $baseline): array
    {
        $anomalies = [];
        foreach ($data as $site => $points) {
            $siteBaseline = $baseline[$site] ?? 0;
            if ($siteBaseline <= 0) {
                continue;
            }
            foreach ($points as $point) {
                if ($point['value'] > $siteBaseline * 3) {
                    $anomalies[] = [
                        'site' => $site,
                        'time' => $point['time'],
                        'value' => $point['value'],
                        'baseline' => $siteBaseline,
                    ];
                }
            }
        }
        return $anomalies;
    }

    /**
     * @return array<int, array{site: string, status: string, uptime_pct: float, last_check: string|null}>
     */
    private function getUptimeData(): array
    {
        // Coba ambil dari cache Uptime Kuma (jika sudah diintegrasikan)
        // Untuk sekarang return empty array - bisa diisi dari integration Uptime Kuma
        $cached = Cache::get('uptime_kuma.status');
        if ($cached && is_array($cached)) {
            return $cached;
        }

        // Placeholder: return empty untuk state awal
        return [];
    }
}