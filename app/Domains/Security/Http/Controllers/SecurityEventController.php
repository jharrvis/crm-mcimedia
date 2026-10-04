<?php

namespace App\Domains\Security\Http\Controllers;

use App\Domains\Security\Models\SecurityIncident;
use App\Domains\Security\Models\SecurityReport;
use App\Domains\Security\Services\SecurityEventIngest;
use App\Domains\Security\Services\SecurityMonitoringIngest;
use App\Domains\Security\Services\UptimeEventIngest;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * API monitoring keamanan (F3-3) — dipanggil script monitoring server klien.
 * Semua rute dilindungi middleware security.api (token dari SECURITY_API_TOKEN).
 */
class SecurityEventController extends Controller
{
    /**
     * POST /api/security/events — terima batch temuan (idempotent).
     */
    public function store(Request $request, SecurityEventIngest $ingest): JsonResponse
    {
        $validated = $request->validate([
            'events' => ['required', 'array', 'min:1', 'max:'.SecurityEventIngest::MAX_EVENTS],
        ], [
            'events.required' => 'Payload wajib berisi array "events".',
            'events.array' => 'Field "events" harus berupa array.',
            'events.min' => 'Minimal satu temuan per request.',
            'events.max' => 'Maksimal '.SecurityEventIngest::MAX_EVENTS.' temuan per request.',
        ]);

        $result = $ingest->ingest($validated['events']);

        return response()->json([
            'status' => 'ok',
            'received' => count($validated['events']),
            'created' => $result['created'],
            'duplicates' => $result['duplicates'],
            'errors' => $result['errors'],
        ]);
    }

    /**
     * POST /api/security/uptime-events — webhook Uptime Kuma (DOWN/UP).
     */
    public function uptimeEvents(Request $request, UptimeEventIngest $ingest): JsonResponse
    {
        $validated = $request->validate([
            'monitor_id' => ['required', 'string', 'max:191'],
            'monitor_name' => ['required', 'string', 'max:255'],
            'url' => ['required', 'string', 'max:500', 'url'],
            'status' => ['required', Rule::in(['down', 'up'])],
            'occurred_at' => ['nullable', 'date'],
            'message' => ['nullable', 'string', 'max:1000'],
        ]);

        $result = $ingest->handle($validated);

        // Return appropriate HTTP status for error responses from ingest service
        $statusCode = 200;
        if (isset($result['status']) && $result['status'] === 'error') {
            $statusCode = match ($result['action']) {
                'validate', 'validate_monitor_id', 'validate_external_id_time', 'extract_domain' => 422,
                'resolve_client' => 400,
                default => 400,
            };
        }

        return response()->json($result, $statusCode);
    }

    /**
     * GET /api/security/status — health check untuk script monitoring.
     */
    public function status(): JsonResponse
    {
        return response()->json([
            'status' => 'ok',
            'service' => 'crm-security-api',
            'time' => now()->toIso8601String(),
            'counts' => [
                'incidents_total' => SecurityIncident::count(),
                'incidents_open' => SecurityIncident::open()->count(),
                'reports_total' => SecurityReport::count(),
            ],
        ]);
    }

    /**
     * POST /api/security/monitoring/events — terima batch data WAF, brute force, rate limit dari aggregator.
     *
     * Payload:
     * {
     *   "events": [
     *     {
     *       "client_id": 1,
     *       "occurred_at": "2026-10-04 10:30:00",
     *       "type": "waf_block|brute_force|rate_limit",
     *       "ip": "192.168.1.1",
     *       "target_url": "https://example.com/wp-login.php",
     *       "country": "CN",
     *       "severity": "critical|high|medium|low|info",
     *       "details": { ... }
     *     }
     *   ]
     * }
     */
    public function storeMonitoringEvents(Request $request, SecurityMonitoringIngest $ingest): JsonResponse
    {
        $validated = $request->validate([
            'events' => ['required', 'array', 'min:1', 'max:' . SecurityMonitoringIngest::MAX_EVENTS],
        ], [
            'events.required' => 'Payload wajib berisi array "events".',
            'events.array' => 'Field "events" harus berupa array.',
            'events.min' => 'Minimal satu temuan per request.',
            'events.max' => 'Maksimal ' . SecurityMonitoringIngest::MAX_EVENTS . ' temuan per request.',
        ]);

        $result = $ingest->ingestEvents($validated['events']);

        return response()->json([
            'status' => 'ok',
            'received' => count($validated['events']),
            'created' => $result['created'],
            'duplicates' => $result['duplicates'],
            'errors' => $result['errors'],
        ]);
    }

    /**
     * POST /api/security/monitoring/ssl — terima data sertifikat SSL dari aggregator.
     *
     * Payload:
     * {
     *   "certificates": [
     *     {
     *       "client_id": 1,
     *       "domain": "example.com",
     *       "issuer": "Let's Encrypt",
     *       "expires_at": "2026-12-31 23:59:59",
     *       "status": "valid|expired|expiring",
     *       "san_domains": ["www.example.com", "api.example.com"]
     *     }
     *   ]
     * }
     */
    public function storeSslData(Request $request, SecurityMonitoringIngest $ingest): JsonResponse
    {
        $validated = $request->validate([
            'certificates' => ['required', 'array', 'min:1', 'max:' . SecurityMonitoringIngest::MAX_EVENTS],
        ], [
            'certificates.required' => 'Payload wajib berisi array "certificates".',
            'certificates.array' => 'Field "certificates" harus berupa array.',
            'certificates.min' => 'Minimal satu sertifikat per request.',
            'certificates.max' => 'Maksimal ' . SecurityMonitoringIngest::MAX_EVENTS . ' sertifikat per request.',
        ]);

        $result = $ingest->ingestSslCertificates($validated['certificates']);

        return response()->json([
            'status' => 'ok',
            'received' => count($validated['certificates']),
            'created' => $result['created'],
            'updated' => $result['updated'],
            'errors' => $result['errors'],
        ]);
    }

    /**
     * POST /api/security/monitoring/traffic — terima data traffic/requests per site dari aggregator.
     *
     * Payload:
     * {
     *   "traffic": [
     *     {
     *       "client_id": 1,
     *       "site": "example.com",
     *       "timestamp": "2026-10-04 10:30:00",
     *       "requests_per_second": 125.5,
     *       "bytes_in": 1024000,
     *       "bytes_out": 2048000
     *     }
     *   ]
     * }
     */
    public function storeTrafficData(Request $request, SecurityMonitoringIngest $ingest): JsonResponse
    {
        $validated = $request->validate([
            'traffic' => ['required', 'array', 'min:1', 'max:' . SecurityMonitoringIngest::MAX_EVENTS],
        ], [
            'traffic.required' => 'Payload wajib berisi array "traffic".',
            'traffic.array' => 'Field "traffic" harus berupa array.',
            'traffic.min' => 'Minimal satu data point per request.',
            'traffic.max' => 'Maksimal ' . SecurityMonitoringIngest::MAX_EVENTS . ' data point per request.',
        ]);

        $result = $ingest->ingestTrafficData($validated['traffic']);

        return response()->json([
            'status' => 'ok',
            'received' => count($validated['traffic']),
            'created' => $result['created'],
            'errors' => $result['errors'],
        ]);
    }

    /**
     * GET /api/security/monitoring/status — health check untuk script aggregator.
     */
    public function monitoringStatus(): JsonResponse
    {
        return response()->json([
            'status' => 'ok',
            'service' => 'crm-security-monitoring-api',
            'time' => now()->toIso8601String(),
            'endpoints' => [
                'events' => '/api/security/monitoring/events',
                'ssl' => '/api/security/monitoring/ssl',
                'traffic' => '/api/security/monitoring/traffic',
            ],
        ]);
    }
}
