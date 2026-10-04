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
 *    source=monitor, severity=critical (P1), title="[domain] Website down"
 *    - Jika insiden OPEN untuk monitor_id sama -> tambah event/timeline, JANGAN buat baru
 *    - Jika insiden RESOLVED < 30 menit lalu -> RE-OPEN insiden lama + flag is_flapping=true
 *    - flap_count per monitor, reset jika stabil lebih dari 1 jam
 * 3. status=up -> cari insiden open dengan external_id LIKE uptime-kuma:{monitor_id}:%
 *    lalu tandai resolved (status+resolved_at); bila tidak ada yang open, abaikan.
 * 4. occurred_at opsional, default now().
 * 5. Idempotensi mengandalkan external_id unik per episode (Uptime Kuma hanya
 *    kirim sekali per perubahan status).
 * 6. Insiden P1 baru -> kirim WA via Fonnte + buat kartu kanban di board mci-team
 */
class UptimeEventIngest
{
    public function __construct(
        private readonly SecurityEventIngest $securityEventIngest,
        private readonly IncidentFonnteNotifier $fonnteNotifier,
        private readonly IncidentKanbanCardCreator $kanbanCardCreator,
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

        // Validasi monitor_id terhadap allowlist (jika dikonfigurasi)
        $monitorValidation = $this->validateMonitorId($data['monitor_id']);
        if ($monitorValidation) {
            return $monitorValidation;
        }

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

    /**
     * Validasi monitor_id terhadap allowlist yang dikonfigurasi.
     * Jika allowlist kosong (tidak dikonfigurasi), validasi dilewati.
     *
     * @return array{status: string, action: string, message?: string}|null
     */
    private function validateMonitorId(string $monitorId): ?array
    {
        $validMonitorIds = config('crm.security.uptime_valid_monitor_ids', []);

        // Jika allowlist tidak dikonfigurasi (kosong), lewati validasi untuk backward compat
        if (empty($validMonitorIds)) {
            return null;
        }

        if (! in_array($monitorId, $validMonitorIds, true)) {
            logger()->warning('Uptime webhook rejected: unknown monitor_id', [
                'monitor_id' => $monitorId,
                'valid_monitor_ids' => $validMonitorIds,
                'ip' => request()->ip(),
            ]);

            return [
                'status' => 'error',
                'action' => 'validate_monitor_id',
                'message' => "Monitor ID {$monitorId} tidak terdaftar di allowlist Uptime Kuma.",
            ];
        }

        return null;
    }

    /**
     * Validasi konsistensi waktu antara external_id dan occurred_at.
     * external_id format: uptime-kuma:{monitor_id}:{YmdHi}
     *
     * @return array{status: string, action: string, message?: string}|null
     */
    private function validateExternalIdTimeConsistency(string $externalId, string $occurredAt): ?array
    {
        // Ekstrak komponen waktu dari external_id (format: uptime-kuma:{monitor_id}:{YmdHi})
        if (! preg_match('/^uptime-kuma:[^:]+:(\d{12})$/', $externalId, $matches)) {
            // Format tidak dikenali (tidak mengikuti pola) - abaikan validasi ini
            return null;
        }

        $externalIdTimeStr = $matches[1]; // YmdHi format
        try {
            $externalIdTime = Carbon::createFromFormat('YmdHi', $externalIdTimeStr);
        } catch (\Throwable) {
            // Format waktu tidak valid - abaikan validasi ini
            return null;
        }

        try {
            $occurredAtCarbon = Carbon::parse($occurredAt);
        } catch (\Throwable) {
            // occurred_at tidak valid - abaikan validasi ini (sudah divalidasi di rules)
            return null;
        }

        $toleranceMinutes = config('crm.security.uptime_external_id_time_tolerance_minutes', 2);
        $diffMinutes = abs($externalIdTime->diffInMinutes($occurredAtCarbon));

        if ($diffMinutes > $toleranceMinutes) {
            logger()->warning('Uptime webhook rejected: external_id time mismatch', [
                'external_id' => $externalId,
                'external_id_time' => $externalIdTime->toIso8601String(),
                'occurred_at' => $occurredAtCarbon->toIso8601String(),
                'diff_minutes' => $diffMinutes,
                'tolerance_minutes' => $toleranceMinutes,
                'ip' => request()->ip(),
            ]);

            return [
                'status' => 'error',
                'action' => 'validate_external_id_time',
                'message' => "Komponen waktu di external_id tidak cocok dengan occurred_at (selisih {$diffMinutes} menit, toleransi {$toleranceMinutes} menit).",
            ];
        }

        return null;
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
     * Handle DOWN event dengan logika dedup, re-open, flapping.
     *
     * @return array{status: string, incident_id: int, action: string}
     */
    private function handleDown(int $clientId, string $domain, string $monitorId, string $occurredAt, string $monitorName, string $message): array
    {
        $carbon = Carbon::parse($occurredAt);
        $externalId = "uptime-kuma:{$monitorId}:{$carbon->format('YmdHi')}";

        // Validasi konsistensi waktu external_id vs occurred_at
        $timeValidation = $this->validateExternalIdTimeConsistency($externalId, $occurredAt);
        if ($timeValidation) {
            return $timeValidation;
        }

        // 1. Cek apakah sudah ada insiden OPEN untuk monitor_id ini (dedup saat OPEN)
        $openIncident = SecurityIncident::query()
            ->where('client_id', $clientId)
            ->where('external_id', 'LIKE', "uptime-kuma:{$monitorId}:%")
            ->open()
            ->first();

        if ($openIncident) {
            // Insiden masih OPEN untuk monitor ini -> duplicate DOWN, tambah timeline
            return [
                'status' => 'ok',
                'incident_id' => $openIncident->id,
                'action' => 'down_duplicate',
            ];
        }

        // 2. Cek apakah ada insiden RESOLVED untuk monitor_id ini dalam 30 menit terakhir (re-open + flapping)
        $recentResolved = SecurityIncident::query()
            ->where('client_id', $clientId)
            ->where('external_id', 'LIKE', "uptime-kuma:{$monitorId}:%")
            ->where('status', IncidentStatus::Resolved)
            ->where('resolved_at', '>=', $carbon->copy()->subMinutes(30))
            ->latest('resolved_at')
            ->first();

        if ($recentResolved) {
            // Re-open insiden lama + flag flapping
            return $this->reopenIncident($recentResolved, $carbon, $message);
        }

        // 3. Cek flap_count: hitung DOWN dalam 1 jam terakhir untuk monitor ini
        $flapCount = $this->calculateFlapCount($clientId, $monitorId, $carbon);

        // 4. Buat insiden BARU (P1 = critical)
        try {
            $incident = SecurityIncident::create([
                'client_id' => $clientId,
                'external_id' => $externalId,
                'occurred_at' => $carbon,
                'severity' => IncidentSeverity::Critical, // P1
                'source' => IncidentSource::Monitor,
                'title' => "[{$domain}] Website down",
                'description' => $message ?: "Uptime Kuma mendeteksi monitor {$monitorName} (ID: {$monitorId}) DOWN pada {$carbon->format('Y-m-d H:i:s')}.",
                'status' => IncidentStatus::Open,
                'is_flapping' => $flapCount >= 3, // P3 jika flapping > 3x dalam 1 jam
                'flap_count' => $flapCount + 1,
                'is_major' => false,
                'acknowledged_at' => null,
            ]);

            // Kirim notifikasi WA untuk P1 baru
            $this->fonnteNotifier->notifyP1Created($incident);

            // Buat kartu kanban untuk P1 baru
            $this->kanbanCardCreator->createForP1Incident($incident);

            // Kirim notifikasi flapping jika applicable
            if ($incident->is_flapping) {
                $this->fonnteNotifier->notifyFlapping($incident);
            }

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
     * Re-open insiden yang sudah resolved dalam 30 menit terakhir.
     */
    private function reopenIncident(SecurityIncident $incident, Carbon $occurredAt, string $message): array
    {
        $newFlapCount = ($incident->flap_count ?? 0) + 1;

        $incident->update([
            'status' => IncidentStatus::Open,
            'resolved_at' => null,
            'severity' => IncidentSeverity::Critical, // Kembali ke P1 saat re-open
            'is_flapping' => true,
            'flap_count' => $newFlapCount,
            'is_major' => false,
            'acknowledged_at' => null,
            'description' => ($incident->description ?? '') . "\n\n---\n**RE-OPENED** pada {$occurredAt->format('Y-m-d H:i:s')}: DOWN terdeteksi lagi dalam 30 menit setelah resolve. Flap count: {$newFlapCount}. " . $message,
        ]);

        // Kirim notifikasi WA untuk flapping
        $this->fonnteNotifier->notifyFlapping($incident);

        // Buat kartu kanban jika belum ada (re-open P1 juga butuh perhatian)
        $this->kanbanCardCreator->createForP1Incident($incident);

        return [
            'status' => 'ok',
            'incident_id' => $incident->id,
            'action' => 'down_reopened_flapping',
        ];
    }

    /**
     * Hitung jumlah flapping (DOWN events) dalam 1 jam terakhir untuk monitor ini.
     */
    private function calculateFlapCount(int $clientId, string $monitorId, Carbon $now): int
    {
        return SecurityIncident::query()
            ->where('client_id', $clientId)
            ->where('external_id', 'LIKE', "uptime-kuma:{$monitorId}:%")
            ->where('occurred_at', '>=', $now->copy()->subHour())
            ->count();
    }

    /**
     * Handle UP event - resolve insiden open.
     *
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

        // Kirim notifikasi WA untuk recovery
        $this->fonnteNotifier->notifyRecovered($incident);

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