<?php

namespace App\Domains\Security\Http\Controllers;

use App\Domains\Security\Models\SecurityIncident;
use App\Domains\Security\Models\SecurityReport;
use App\Domains\Security\Services\SecurityEventIngest;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

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
