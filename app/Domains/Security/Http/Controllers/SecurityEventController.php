<?php

namespace App\Domains\Security\Http\Controllers;

use App\Domains\Security\Models\SecurityIncident;
use App\Domains\Security\Models\SecurityReport;
use App\Domains\Security\Services\SecurityEventIngest;
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

        return response()->json($result);
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
}
