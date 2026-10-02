<?php

namespace App\Domains\Providers\Drivers;

use App\Domains\Providers\Contracts\DomainInfo;
use App\Domains\Providers\Exceptions\ProviderNotConfigured;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * Driver manual (F4-5) — untuk registrar/penyedia yang belum punya API.
 *
 * Kredensial berisi satu field `domains_json`: array JSON
 *   [{"domain": "contoh.com", "expires_at": "2027-03-01", "status": "active"}, ...]
 * sehingga admin dapat mencatat domain + tanggal kedaluwarsanya secara manual.
 * Driver ini sekaligus contoh bahwa driver baru cukup mendefinisikan skema
 * kredensialnya sendiri — tanpa menambah kolom di tabel `domain_providers`.
 */
class ManualDomainProviderDriver extends AbstractDomainProviderDriver
{
    public static function key(): string
    {
        return 'manual';
    }

    public static function label(): string
    {
        return 'Manual (tanpa API)';
    }

    public static function credentialFields(): array
    {
        return [
            'domains_json' => [
                'label' => 'Daftar domain (JSON)',
                'type' => 'textarea',
                'required' => true,
                'help' => '[{"domain":"contoh.com","expires_at":"2027-03-01","status":"active"}]',
            ],
        ];
    }

    public function listDomains(): array
    {
        $rows = $this->rows();
        $domains = [];

        foreach ($rows as $row) {
            $domain = trim((string) ($row['domain'] ?? ''));

            if ($domain === '') {
                continue;
            }

            $domains[] = new DomainInfo(
                domain: $domain,
                expiresAt: $this->parseDate($row['expires_at'] ?? null),
                status: (string) ($row['status'] ?? 'active'),
                raw: $row,
            );
        }

        return $domains;
    }

    public function getExpiry(string $domain): ?CarbonInterface
    {
        foreach ($this->listDomains() as $info) {
            if (strcasecmp($info->domain, $domain) === 0) {
                return $info->expiresAt;
            }
        }

        return null;
    }

    /**
     * Baris domain dari kredensial. JSON tak valid / bukan array → dianggap
     * kosong (bukan exception) agar UI menampilkan pesan "belum ada domain".
     *
     * @return list<array<string, mixed>>
     */
    private function rows(): array
    {
        $raw = $this->credential('domains_json');

        if ($raw === null) {
            throw ProviderNotConfigured::for($this->provider->name, static::key());
        }

        $decoded = is_array($raw) ? $raw : json_decode((string) $raw, true);

        if (! is_array($decoded)) {
            return [];
        }

        return array_values(array_filter($decoded, 'is_array'));
    }

    private function parseDate(mixed $value): ?CarbonInterface
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        try {
            return CarbonImmutable::parse($value);
        } catch (\Throwable) {
            return null;
        }
    }
}
