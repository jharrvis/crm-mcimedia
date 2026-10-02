<?php

namespace App\Domains\Security\Services;

use App\Domains\Security\Enums\IncidentSeverity;
use App\Domains\Security\Enums\IncidentSource;
use App\Domains\Security\Enums\IncidentStatus;
use App\Domains\Security\Models\SecurityIncident;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

/**
 * Menyerap batch temuan dari script monitoring (F3-3).
 *
 * Idempotensi:
 *  - bila `external_id` dikirim (dan sudah pernah tersimpan untuk klien yang
 *    sama) → dianggap duplikat, tidak membuat insiden baru;
 *  - bila tidak ada `external_id` → dedup window: temuan dengan klien+sumber+
 *    judul sama dalam N menit terakhir dianggap duplikat.
 *
 * Entri yang tidak valid TIDAK menggagalkan seluruh batch: dilaporkan pada
 * daftar `errors`, sementara entri valid tetap diproses.
 *
 * Keamanan: hanya field di bawah yang dibaca — field lain (mis. kredensial
 * server bila script keliru mengirimnya) diabaikan diam-diam dan tidak
 * pernah disimpan.
 */
class SecurityEventIngest
{
    public const MAX_EVENTS = 200;

    /**
     * @param  array<int, mixed>  $events
     * @return array{created: int, duplicates: int, errors: array<int, array{index: int, message: string}>}
     */
    public function ingest(array $events): array
    {
        $created = 0;
        $duplicates = 0;
        $errors = [];

        foreach (array_values($events) as $index => $event) {
            if (! is_array($event)) {
                $errors[] = ['index' => $index, 'message' => 'Entri harus berupa objek.'];

                continue;
            }

            $validator = Validator::make($event, $this->rules());

            if ($validator->fails()) {
                $errors[] = ['index' => $index, 'message' => (string) $validator->errors()->first()];

                continue;
            }

            $data = $validator->validated();

            if ($this->isDuplicate($data)) {
                $duplicates++;

                continue;
            }

            try {
                SecurityIncident::create([
                    'client_id' => (int) $data['client_id'],
                    'external_id' => $data['external_id'] ?? null,
                    'occurred_at' => Carbon::parse($data['occurred_at']),
                    'severity' => $data['severity'],
                    'source' => $data['source'],
                    'title' => $data['title'],
                    'description' => $data['description'] ?? null,
                    'status' => IncidentStatus::Open,
                ]);
                $created++;
            } catch (QueryException $e) {
                // Balapan konkuren pada unique(client_id, external_id): anggap duplikat.
                if ($this->isUniqueViolation($e)) {
                    $duplicates++;

                    continue;
                }
                throw $e;
            }
        }

        return compact('created', 'duplicates', 'errors');
    }

    /** @return array<string, array<int, mixed>> */
    private function rules(): array
    {
        return [
            'external_id' => ['nullable', 'string', 'max:191'],
            'client_id' => ['required', 'integer', 'exists:clients,id'],
            'occurred_at' => ['required', 'date'],
            'severity' => ['required', Rule::enum(IncidentSeverity::class)],
            'source' => ['required', Rule::enum(IncidentSource::class)],
            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:5000'],
        ];
    }

    /** @param array<string, mixed> $data */
    private function isDuplicate(array $data): bool
    {
        $clientId = (int) $data['client_id'];

        if (filled($data['external_id'] ?? null)) {
            return SecurityIncident::query()
                ->where('client_id', $clientId)
                ->where('external_id', $data['external_id'])
                ->exists();
        }

        $window = max(0, (int) config('crm.security.dedup_window_minutes', 1440));

        return SecurityIncident::query()
            ->where('client_id', $clientId)
            ->where('source', $data['source'])
            ->where('title', $data['title'])
            ->where('created_at', '>=', now()->subMinutes($window))
            ->exists();
    }

    private function isUniqueViolation(QueryException $e): bool
    {
        $sqlState = $e->errorInfo[0] ?? null;

        // 23000 (MySQL/SQLite) dan 23505 (PostgreSQL).
        return in_array($sqlState, ['23000', '23505'], true);
    }
}
