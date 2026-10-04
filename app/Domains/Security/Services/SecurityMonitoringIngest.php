<?php

namespace App\Domains\Security\Services;

use App\Domains\Clients\Models\Client;
use App\Domains\Security\Enums\IncidentSeverity;
use App\Domains\Security\Enums\IncidentSource;
use App\Domains\Security\Enums\IncidentStatus;
use App\Domains\Security\Models\SecurityIncident;
use App\Domains\Security\Models\SslCertificate;
use App\Domains\Security\Models\MonitoringEvent;
use App\Domains\Security\Models\TrafficData;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

/**
 * Menyerap data dari script aggregator monitoring (devops task companion).
 *
 * Endpoint:
 * - POST /api/security/monitoring/events    → ingestEvents()
 * - POST /api/security/monitoring/ssl       → ingestSslCertificates()
 * - POST /api/security/monitoring/traffic   → ingestTrafficData()
 *
 * Idempotensi:
 * - events: dedup via external_id (client_id + type + ip + occurred_at hash)
 * - ssl: upsert via unique (client_id, domain)
 * - traffic: dedup via unique (client_id, site, timestamp)
 */
class SecurityMonitoringIngest
{
    public const MAX_EVENTS = 500;

    /**
     * Ingest batch event keamanan (WAF block, brute force, rate limit).
     *
     * @param  array<int, array<string, mixed>>  $events
     * @return array{created: int, duplicates: int, errors: array<int, array{index: int, message: string}>}
     */
    public function ingestEvents(array $events): array
    {
        $created = 0;
        $duplicates = 0;
        $errors = [];

        foreach (array_values($events) as $index => $event) {
            if (! is_array($event)) {
                $errors[] = ['index' => $index, 'message' => 'Entri harus berupa objek.'];
                continue;
            }

            $validator = Validator::make($event, $this->eventRules());

            if ($validator->fails()) {
                $errors[] = ['index' => $index, 'message' => (string) $validator->errors()->first()];
                continue;
            }

            $data = $validator->validated();

            // Cek duplikat via external_id hash
            $externalId = $this->generateEventExternalId($data);
            if (MonitoringEvent::query()
                ->where('client_id', $data['client_id'])
                ->where('external_id', $externalId)
                ->exists()) {
                $duplicates++;
                continue;
            }

            try {
                MonitoringEvent::create([
                    'client_id' => (int) $data['client_id'],
                    'external_id' => $externalId,
                    'occurred_at' => Carbon::parse($data['occurred_at']),
                    'type' => $data['type'],
                    'ip' => $data['ip'],
                    'target_url' => $data['target_url'],
                    'country' => $data['country'] ?? null,
                    'severity' => $data['severity'],
                    'details' => $data['details'] ?? null,
                ]);
                $created++;

                // Auto-create incident untuk anomali severity critical/high
                if (in_array($data['severity'], [IncidentSeverity::Critical->value, IncidentSeverity::High->value], true)) {
                    $this->createIncidentFromEvent($data);
                }
            } catch (QueryException $e) {
                if ($this->isUniqueViolation($e)) {
                    $duplicates++;
                    continue;
                }
                throw $e;
            }
        }

        return compact('created', 'duplicates', 'errors');
    }

    /**
     * Ingest batch sertifikat SSL (upsert).
     *
     * @param  array<int, array<string, mixed>>  $certificates
     * @return array{created: int, updated: int, errors: array<int, array{index: int, message: string}>}
     */
    public function ingestSslCertificates(array $certificates): array
    {
        $created = 0;
        $updated = 0;
        $errors = [];

        foreach (array_values($certificates) as $index => $cert) {
            if (! is_array($cert)) {
                $errors[] = ['index' => $index, 'message' => 'Entri harus berupa objek.'];
                continue;
            }

            $validator = Validator::make($cert, $this->sslRules());

            if ($validator->fails()) {
                $errors[] = ['index' => $index, 'message' => (string) $validator->errors()->first()];
                continue;
            }

            $data = $validator->validated();

            try {
                $sslCert = SslCertificate::updateOrCreate(
                    [
                        'client_id' => (int) $data['client_id'],
                        'domain' => $data['domain'],
                    ],
                    [
                        'issuer' => $data['issuer'] ?? null,
                        'expires_at' => Carbon::parse($data['expires_at']),
                        'status' => $data['status'] ?? 'valid',
                        'san_domains' => $data['san_domains'] ?? [],
                        'last_checked_at' => now(),
                    ]
                );

                if ($sslCert->wasRecentlyCreated) {
                    $created++;
                } else {
                    $updated++;
                }
            } catch (QueryException $e) {
                if ($this->isUniqueViolation($e)) {
                    // Race condition: coba update lagi
                    SslCertificate::where('client_id', (int) $data['client_id'])
                        ->where('domain', $data['domain'])
                        ->update([
                            'issuer' => $data['issuer'] ?? null,
                            'expires_at' => Carbon::parse($data['expires_at']),
                            'status' => $data['status'] ?? 'valid',
                            'san_domains' => $data['san_domains'] ?? [],
                            'last_checked_at' => now(),
                        ]);
                    $updated++;
                    continue;
                }
                throw $e;
            }
        }

        return compact('created', 'updated', 'errors');
    }

    /**
     * Ingest batch data traffic.
     *
     * @param  array<int, array<string, mixed>>  $traffic
     * @return array{created: int, errors: array<int, array{index: int, message: string}>}
     */
    public function ingestTrafficData(array $traffic): array
    {
        $created = 0;
        $errors = [];

        foreach (array_values($traffic) as $index => $point) {
            if (! is_array($point)) {
                $errors[] = ['index' => $index, 'message' => 'Entri harus berupa objek.'];
                continue;
            }

            $validator = Validator::make($point, $this->trafficRules());

            if ($validator->fails()) {
                $errors[] = ['index' => $index, 'message' => (string) $validator->errors()->first()];
                continue;
            }

            $data = $validator->validated();

            // Upsert via unique (client_id, site, timestamp)
            try {
                TrafficData::updateOrCreate(
                    [
                        'client_id' => (int) $data['client_id'],
                        'site' => $data['site'],
                        'timestamp' => Carbon::parse($data['timestamp']),
                    ],
                    [
                        'requests_per_second' => (float) $data['requests_per_second'],
                        'bytes_in' => $data['bytes_in'] ?? 0,
                        'bytes_out' => $data['bytes_out'] ?? 0,
                    ]
                );
                $created++;
            } catch (QueryException $e) {
                if ($this->isUniqueViolation($e)) {
                    // Race condition - data sudah ada
                    continue;
                }
                throw $e;
            }
        }

        return compact('created', 'errors');
    }

    /** @return array<string, array<int, mixed>> */
    private function eventRules(): array
    {
        return [
            'client_id' => ['required', 'integer', 'exists:clients,id'],
            'occurred_at' => ['required', 'date'],
            'type' => ['required', Rule::in(['waf_block', 'brute_force', 'rate_limit'])],
            'ip' => ['required', 'string', 'max:45'],
            'target_url' => ['required', 'string', 'max:500', 'url'],
            'country' => ['nullable', 'string', 'size:2'],
            'severity' => ['required', Rule::enum(IncidentSeverity::class)],
            'details' => ['nullable', 'array'],
        ];
    }

    /** @return array<string, array<int, mixed>> */
    private function sslRules(): array
    {
        return [
            'client_id' => ['required', 'integer', 'exists:clients,id'],
            'domain' => ['required', 'string', 'max:255'],
            'issuer' => ['nullable', 'string', 'max:255'],
            'expires_at' => ['required', 'date'],
            'status' => ['nullable', Rule::in(['valid', 'expired', 'expiring'])],
            'san_domains' => ['nullable', 'array'],
            'san_domains.*' => ['string', 'max:255'],
        ];
    }

    /** @return array<string, array<int, mixed>> */
    private function trafficRules(): array
    {
        return [
            'client_id' => ['required', 'integer', 'exists:clients,id'],
            'site' => ['required', 'string', 'max:255'],
            'timestamp' => ['required', 'date'],
            'requests_per_second' => ['required', 'numeric', 'min:0'],
            'bytes_in' => ['nullable', 'integer', 'min:0'],
            'bytes_out' => ['nullable', 'integer', 'min:0'],
        ];
    }

    private function generateEventExternalId(array $data): string
    {
        $raw = $data['client_id'] . '|' . $data['type'] . '|' . $data['ip'] . '|' . $data['occurred_at'];
        return hash('sha256', $raw);
    }

    /**
     * Buat insiden otomatis dari event severity critical/high.
     */
    private function createIncidentFromEvent(array $data): void
    {
        $severity = IncidentSeverity::from($data['severity']);
        $source = match ($data['type']) {
            'waf_block' => IncidentSource::Firewall,
            'brute_force' => IncidentSource::Firewall,
            'rate_limit' => IncidentSource::Monitor,
            default => IncidentSource::Monitor,
        };

        $typeLabels = [
            'waf_block' => 'WAF Block',
            'brute_force' => 'Brute Force',
            'rate_limit' => 'Rate Limit',
        ];

        SecurityIncident::create([
            'client_id' => (int) $data['client_id'],
            'external_id' => 'auto-' . $this->generateEventExternalId($data),
            'occurred_at' => Carbon::parse($data['occurred_at']),
            'severity' => $severity,
            'source' => $source,
            'title' => $typeLabels[$data['type']] . ' dari IP ' . $data['ip'] . ' ke ' . $data['target_url'],
            'description' => 'Dibuat otomatis dari monitoring aggregator. '
                . 'IP: ' . $data['ip'] . ' | Negara: ' . ($data['country'] ?? 'unknown') . ' | '
                . 'Detail: ' . json_encode($data['details'] ?? []),
            'status' => IncidentStatus::Open,
        ]);
    }

    private function isUniqueViolation(QueryException $e): bool
    {
        $sqlState = $e->errorInfo[0] ?? null;
        return in_array($sqlState, ['23000', '23505'], true);
    }
}