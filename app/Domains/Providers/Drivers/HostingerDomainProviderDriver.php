<?php

namespace App\Domains\Providers\Drivers;

use App\Domains\Providers\Contracts\DomainInfo;
use App\Domains\Providers\Exceptions\ProviderApiException;
use App\Domains\Providers\Exceptions\ProviderNotConfigured;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;

/**
 * Driver penyedia domain Hostinger (F4-6).
 *
 * Autentikasi memakai API Token hPanel sebagai Bearer token, disimpan
 * terenkripsi per provider lewat kolom `domain_providers.credentials`
 * (cast `encrypted:array` di model) — BUKAN kredensial global/env.
 *
 * Endpoint yang dipakai:
 *   GET /api/domains/v1/portfolio
 *     → daftar domain beserta tanggal kedaluwarsa (renewal) dan status.
 *
 * `expires_at` dari respons dipetakan ke `DomainInfo::expiresAt` sehingga bisa
 * dibaca lewat `getExpiry()`; sisa info (id, tipe, status, tanggal dibuat)
 * disimpan apa adanya di `DomainInfo::raw` untuk audit.
 *
 * CATATAN: API Hostinger juga menyediakan endpoint layanan hosting
 * (`/api/hosting/v1/websites`), namun kontrak `DomainProviderDriver` (dikelola
 * F4-5) hanya mendefinisikan `listDomains()`/`getExpiry()`. Menampilkan layanan
 * hosting sebagai entitas tersendiri memerlukan penambahan metode pada kontrak
 * bersama itu, sehingga driver ini sengaja TIDAK mengubah kontrak tersebut.
 */
class HostingerDomainProviderDriver extends AbstractDomainProviderDriver
{
    /** Base URL API publik Hostinger. */
    public const DEFAULT_BASE_URL = 'https://developers.hostinger.com';

    /** Endpoint daftar domain (portfolio) milik akun. */
    private const PORTFOLIO_PATH = '/api/domains/v1/portfolio';

    /** Cache hasil portfolio per-instance agar UI tak memanggil API dua kali. */
    private ?array $portfolioCache = null;

    public static function key(): string
    {
        return 'hostinger';
    }

    public static function label(): string
    {
        return 'Hostinger';
    }

    public static function credentialFields(): array
    {
        return [
            'api_token' => [
                'label' => 'API Token (hPanel)',
                'type' => 'password',
                'required' => true,
                'secret' => true,
                'help' => 'Buat di hPanel → Akun → API Token. Cukup beri akses baca domain.',
            ],
            'base_url' => [
                'label' => 'Base URL API',
                'type' => 'text',
                'default' => self::DEFAULT_BASE_URL,
                'help' => 'Ubah hanya bila memakai proxy/mock.',
            ],
            'timeout' => [
                'label' => 'Timeout (detik)',
                'type' => 'number',
                'default' => 30,
            ],
        ];
    }

    public function listDomains(): array
    {
        $domains = [];

        foreach ($this->portfolio() as $row) {
            if (! is_array($row)) {
                continue;
            }

            $domain = trim((string) ($row['domain'] ?? ''));

            if ($domain === '') {
                continue;   // baris tanpa nama domain diabaikan, bukan digagalkan
            }

            $domains[] = new DomainInfo(
                domain: $domain,
                expiresAt: $this->parseDate($row['expires_at'] ?? null),
                status: $this->normalizeStatus($row['status'] ?? null),
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
     * Ambil & validasi daftar domain dari API Hostinger.
     *
     * @return list<array<string, mixed>>
     */
    private function portfolio(): array
    {
        if ($this->portfolioCache !== null) {
            return $this->portfolioCache;
        }

        $this->ensureConfigured();

        try {
            $response = $this->request()->get(self::PORTFOLIO_PATH);
        } catch (ConnectionException) {
            throw ProviderApiException::connectionFailed($this->provider->name, static::key());
        }

        if ($response->failed()) {
            throw ProviderApiException::fromResponse($this->provider->name, static::key(), $response);
        }

        $data = $response->json();

        return $this->portfolioCache = is_array($data) ? array_values($data) : [];
    }

    private function request(): PendingRequest
    {
        return Http::baseUrl($this->baseUrl())
            ->withToken((string) $this->credential('api_token'))
            ->acceptJson()
            ->timeout((int) $this->credential('timeout', 30));
    }

    private function baseUrl(): string
    {
        $base = trim((string) $this->credential('base_url', self::DEFAULT_BASE_URL));

        return rtrim($base !== '' ? $base : self::DEFAULT_BASE_URL, '/');
    }

    /** Status Hostinger dijadikan huruf kecil; kosong → 'active'. */
    private function normalizeStatus(mixed $status): string
    {
        $status = strtolower(trim((string) $status));

        return $status !== '' ? $status : 'active';
    }

    /** Tanggal ISO-8601 dari Hostinger; format tak dikenal → null. */
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

    private function ensureConfigured(): void
    {
        if (blank($this->credential('api_token'))) {
            throw ProviderNotConfigured::for($this->provider->name, static::key());
        }
    }
}
