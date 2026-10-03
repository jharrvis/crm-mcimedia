<?php

namespace App\Domains\Security\Services;

use App\Domains\Clients\Models\Client;
use App\Domains\Security\Enums\IncidentSeverity;
use App\Domains\Security\Enums\IncidentSource;
use App\Domains\Security\Enums\IncidentStatus;
use App\Domains\Security\Models\SecurityIncident;
use App\Domains\Services\Models\Service;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

/**
 * Menyerap webhook Uptime Kuma (DOWN/UP) dan mengubahnya menjadi insiden CRM.
 *
 * Payload Uptime Kuma (Liquid template):
 * - monitor_id: string
 * - monitor_name: string
 * - url: string (full URL, domain diekstrak)
 * - status: 'down' | 'up'
 * - occurred_at: optional ISO8601, default now()
 * - message: string
 *
 * Logic:
 * 1. Ekstrak domain dari URL, resolve client_id via services.name = domain
 *    (tabel services, kolom name berisi domain); fallback ke client default
 *    dari config (mengikuti konvensi CLIENT_ID push-agent).
 * 2. status=down -> buat insiden via SecurityEventIngest:
 *    external_id unik per episode: uptime-kuma:{monitor_id}:{YmdHi occurred_at}
 *    source=monitor, severity=high, title="[domain] Website down"
 * 3. status=up -> cari insiden open dengan external_id LIKE uptime-kuma:{monitor_id}:%
 *    lalu tandai resolved (status+resolved_at); bila tidak ada yang open, abaikan.
 * 4. occurred_at opsional, default now().
 * 5. Idempotensi mengandalkan external_id unik per episode (Uptime Kuma hanya
 *    kirim sekali per perubahan status).
 */
class UptimeEventIngest
{
    public function __construct(
        private readonly SecurityEventIngest $securityEventIngest,
    ) {}

    /**
     * Proses single webhook event dari Uptime Kuma.
     *
     * @param array<string, mixed> $payload
     * @return array{status: string, incident_id?: int, action: string, message?: string}
     */
    public function handle(array $payload): array
    {
        $validator = Validator::make($payload, $this->rules());

        if ($validator->fails()) {
            return [
                'status' => 'error',
                'action' => 'validate',
                'message' => (string) $validator->errors()->first(),
            ];
        }

        $data = $validator->validated();

        // Ekstrak domain dari URL
        $domain = $this->extractDomain($data['url']);
        if (! $domain) {
            return [
                'status' => 'error',
                'action' => 'extract_domain',
                'message' => 'Tidak dapat mengekstrak domain dari URL.',
            ];
        }

        // Resolve client_id
        $clientId = $this->resolveClientId($domain);
        
        $occurredAt = $data['occurred_at'] ?? now()->toIso8601String();
        $monitorId = $data['monitor_id'];

        if ($data['status'] === 'down') {
            if (! $clientId) {
                return [
                    'status' => 'error',
                    'action' => 'resolve_client',
                    'message' => "Tidak ada client untuk domain {$domain} dan tidak ada default client.",
                ];
            }
            return $this->handleDown($clientId, $domain, $monitorId, $occurredAt, $data['monitor_name'], $data['message'] ?? '');
        }

        // Untuk UP: jika tidak bisa resolve client, tidak ada insiden yang bisa di-resolve
        if (! $clientId) {
            return [
                'status' => 'ok',
                'action' => 'up_no_open_incident',
            ];
        }

        return $this->handleUp($clientId, $monitorId, $occurredAt);
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    private function rules(): array
    {
        return [
            'monitor_id' => ['required', 'string', 'max:191'],
            'monitor_name' => ['required', 'string', 'max:255'],
            'url' => ['required', 'string', 'max:500'],
            'status' => ['required', Rule::in(['down', 'up'])],
            'occurred_at' => ['nullable', 'date'],
            'message' => ['nullable', 'string', 'max:1000'],
        ];
    }

    private function extractDomain(string $url): ?string
    {
        $parsed = parse_url($url);

        if (! $parsed || ! isset($parsed['host'])) {
            return null;
        }

        $host = $parsed['host'];

        // Hapus www. prefix jika ada
        return preg_replace('/^www\./', '', $host);
    }

    private function resolveClientId(string $domain): ?int
    {
        // Cari di tabel services berdasarkan name = domain (hanya service aktif)
        $service = Service::query()
            ->active()
            ->where('name', $domain)
            ->first();

        if ($service) {
            return $service->client_id;
        }

        // Fallback ke default client dari config (mengikuti konvensi CLIENT_ID push-agent)
        $defaultClientId = config('crm.security.uptime_default_client_id');

        if ($defaultClientId && Client::find($defaultClientId)?->is_active) {
            return (int) $defaultClientId;
        }

        // Jika tidak ada config, ambil client aktif pertama sebagai fallback terakhir
        return Client::query()->active()->first()?->id;
    }

    /**
     * @return array{status: string, incident_id: int, action: string}
     */
    private function handleDown(int $clientId, string $domain, string $monitorId, string $occurredAt, string $monitorName, string $message): array
    {
        $carbon = Carbon::parse($occurredAt);
        $externalId = "uptime-kuma:{$monitorId}:{$carbon->format('YmdHi')}";

        // Cek apakah sudah ada (idempotensi)
        $existing = SecurityIncident::query()
            ->where('client_id', $clientId)
            ->where('external_id', $externalId)
            ->first();

        if ($existing) {
            return [
                'status' => 'ok',
                'incident_id' => $existing->id,
                'action' => 'down_duplicate',
            ];
        }

        try {
            $incident = SecurityIncident::create([
                'client_id' => $clientId,
                'external_id' => $externalId,
                'occurred_at' => $carbon,
                'severity' => IncidentSeverity::High,
                'source' => IncidentSource::Monitor,
                'title' => "[{$domain}] Website down",
                'description' => $message ?: "Uptime Kuma mendeteksi monitor {$monitorName} (ID: {$monitorId}) DOWN pada {$carbon->format('Y-m-d H:i:s')}.",
                'status' => IncidentStatus::Open,
            ]);

            return [
                'status' => 'ok',
                'incident_id' => $incident->id,
                'action' => 'down_created',
            ];
        } catch (QueryException $e) {
            // Race condition: external_id sudah dibuat request lain
            if ($this->isUniqueViolation($e)) {
                $duplicate = SecurityIncident::query()
                    ->where('client_id', $clientId)
                    ->where('external_id', $externalId)
                    ->first();

                return [
                    'status' => 'ok',
                    'incident_id' => $duplicate?->id,
                    'action' => 'down_duplicate_race',
                ];
            }
            throw $e;
        }
    }

    /**
     * @return array{status: string, incident_id?: int, action: string}
     */
    private function handleUp(int $clientId, string $monitorId, string $occurredAt): array
    {
        // Cari insiden open dengan external_id LIKE uptime-kuma:{monitor_id}:%
        $incident = SecurityIncident::query()
            ->where('client_id', $clientId)
            ->where('external_id', 'LIKE', "uptime-kuma:{$monitorId}:%")
            ->open()
            ->first();

        if (! $incident) {
            // Tidak ada insiden open untuk monitor ini -> abaikan (bisa jadi UP tanpa DOWN sebelumnya)
            return [
                'status' => 'ok',
                'action' => 'up_no_open_incident',
            ];
        }

        $resolvedAt = Carbon::parse($occurredAt);
        $incident->transitionTo(IncidentStatus::Resolved);

        // Pastikan resolved_at diset ke occurred_at dari webhook (bukan now())
        if ($incident->resolved_at->ne($resolvedAt)) {
            $incident->update(['resolved_at' => $resolvedAt]);
        }

        return [
            'status' => 'ok',
            'incident_id' => $incident->id,
            'action' => 'up_resolved',
        ];
    }

    private function isUniqueViolation(QueryException $e): bool
    {
        $sqlState = $e->errorInfo[0] ?? null;

        return in_array($sqlState, ['23000', '23505'], true);
    }
}