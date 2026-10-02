<?php

namespace App\Domains\Providers\Drivers;

use App\Domains\Providers\Contracts\DomainInfo;
use App\Domains\Providers\Exceptions\NameSiloRequestFailed;
use App\Domains\Providers\Exceptions\ProviderNotConfigured;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;

/**
 * Driver penyedia registrar domain NameSilo (F4-7).
 *
 * Autentikasi memakai API key NameSilo (NameSilo → API Manager) yang disimpan
 * TERENKIRIPSI per provider pada `domain_providers.credentials` (cast
 * `encrypted:array`), bukan dari env/global — sama seperti driver lain.
 *
 * Bentuk API NameSilo v1 (diverifikasi langsung ke www.namesilo.com/api):
 *   GET /api/{operation}?version=1&type=json&key={KEY}&...
 *   → { "request": {...}, "reply": { "code": 300, "detail": "success", ... } }
 *
 * Perilaku penting yang ditangani driver ini:
 *   1. SEMUA operasi wajib GET; parameter berada di query string.
 *   2. Kesalahan API dikembalikan sebagai HTTP 200 dengan `reply.code` != 300
 *      (mis. "110" = kunci tidak valid, 200 = domain bukan milik akun) —
 *      jadi `reply.code` wajib diperiksa, bukan hanya status HTTP.
 *   3. `reply.code` bertipe string pada error dan int pada sukses.
 *   4. `listDomains` hanya memberi nama + created/expires; status, lock, privacy,
 *      dan auto-renew hanya ada di `getDomainInfo` (per domain). Karena itu
 *      `enrich_details` (default aktif) menentukan apakah driver melakukan
 *      penambahan detail tersebut.
 *   5. TIDAK ada operasi daftar auto-renew; flag auto-renew hanya dari
 *      `getDomainInfo`.
 *
 * Auto-renew adalah satu-satunya operasi TULIS (`setAutoRenew`). Semua operasi
 * lain read-only. Kegagalan di lapisan driver tidak pernah membocorkan API key:
 * pesan exception dibangun dari nama operasi saja (lihat NameSiloRequestFailed).
 */
class NameSiloDomainProviderDriver extends AbstractDomainProviderDriver
{
    /** Basis URL API NameSilo (dokumentasi resmi: /api/{operation}). */
    public const DEFAULT_BASE_URL = 'https://www.namesilo.com/api';

    /** Versi API NameSilo yang dipakai. */
    public const API_VERSION = '1';

    /** `reply.code` untuk operasi sukses. */
    private const CODE_SUCCESS = 300;

    /** `reply.code` untuk domain tidak aktif / bukan milik akun ini. */
    private const CODE_DOMAIN_NOT_OWNED = 200;

    /** Cache daftar domain agar UI tidak memanggil API dua kali. */
    private ?array $domainCache = null;

    /** Cache detail per domain (huruf kecil) selama instance hidup. */
    private array $detailsCache = [];

    public static function key(): string
    {
        return 'namesilo';
    }

    public static function label(): string
    {
        return 'NameSilo (registrar domain)';
    }

    public static function credentialFields(): array
    {
        return [
            'api_key' => [
                'label' => 'API key NameSilo',
                'type' => 'password',
                'required' => true,
                'secret' => true,
                'help' => 'Buat di NameSilo → API Manager. Disimpan terenkripsi di database.',
            ],
            'base_url' => [
                'label' => 'Base URL API',
                'type' => 'text',
                'default' => self::DEFAULT_BASE_URL,
                'help' => 'Ubah hanya bila memakai proxy/mock.',
            ],
            'enrich_details' => [
                'label' => 'Tarik status & auto-renew tiap domain',
                'type' => 'checkbox',
                'default' => true,
                'help' => 'Satu request tambahan (getDomainInfo) per domain. Nonaktifkan bila portofolio sangat besar.',
            ],
            'timeout' => [
                'label' => 'Timeout (detik)',
                'type' => 'number',
                'default' => 20,
            ],
        ];
    }

    /**
     * Daftar domain milik akun, lengkap dengan kedaluwarsa.
     *
     * Status/auto-renew ikut diambil bila `enrich_details` aktif (default),
     * karena `listDomains` tidak menyediakan keduanya.
     *
     * @return list<DomainInfo>
     */
    public function listDomains(): array
    {
        $domains = [];

        foreach ($this->rawDomains() as $row) {
            if (! is_array($row)) {
                continue;
            }

            $domain = trim((string) ($row['domain'] ?? ''));

            if ($domain === '') {
                continue;   // baris tanpa nama domain dilewati, bukan digagalkan
            }

            $details = $this->enrichDetails() ? $this->detailsOrNull($domain) : null;

            $domains[] = new DomainInfo(
                domain: $domain,
                expiresAt: $this->parseDate($details['expires'] ?? $row['expires'] ?? null),
                status: $this->normalizeStatus($details['status'] ?? null),
                raw: $details ?? $row,
            );
        }

        return $domains;
    }

    /**
     * Tanggal kedaluwarsa satu domain.
     *
     * Diambil langsung dari `getDomainInfo` bila tersedia; bila domain bukan
     * milik akun ini (kode 200) → null.
     */
    public function getExpiry(string $domain): ?CarbonInterface
    {
        $details = $this->detailsOrNull($domain);

        return $this->parseDate($details['expires'] ?? null);
    }

    /**
     * Detail ternormalisasi satu domain, atau null bila domain tidak aktif /
     * bukan milik akun ini.
     *
     * Catatan: respons `getDomainInfo` NameSilo TIDAK menyertakan field
     * `domain`, jadi nilainya berasal dari argumen.
     *
     * @return array{
     *     domain: string,
     *     status: string,
     *     auto_renew: ?bool,
     *     locked: ?bool,
     *     private: ?bool,
     *     created: ?string,
     *     expires: ?string,
     *     nameservers: list<string>
     * }|null
     */
    public function details(string $domain): ?array
    {
        $cacheKey = mb_strtolower(trim($domain));

        if (array_key_exists($cacheKey, $this->detailsCache)) {
            return $this->detailsCache[$cacheKey];
        }

        try {
            $reply = $this->call('getDomainInfo', ['domain' => $domain]);
        } catch (NameSiloRequestFailed $e) {
            if ($e->apiCode() === self::CODE_DOMAIN_NOT_OWNED) {
                return $this->detailsCache[$cacheKey] = null;
            }

            throw $e;
        }

        return $this->detailsCache[$cacheKey] = $this->normalizeDetails($domain, $reply);
    }

    /** Status netral satu domain ('active', 'expired', ...); null bila tak diketahui. */
    public function status(string $domain): ?string
    {
        // details() sudah menyimpan status dalam bentuk netral.
        return $this->details($domain)['status'] ?? null;
    }

    /** Bendera auto-renew satu domain; null bila domain tidak diketahui. */
    public function isAutoRenewEnabled(string $domain): ?bool
    {
        return $this->details($domain)['auto_renew'] ?? null;
    }

    /**
     * Aktif/nonaktifkan auto-renew satu domain (operasi TULIS).
     *
     * Memakai `addAutoRenewal` / `removeAutoRenewal`. Melempar
     * NameSiloRequestFailed bila NameSilo menolak (mis. kode 110 bila API key
     * tidak punya izin). Cache detail dikosongkan supaya UI mencerminkan
     * status terbaru setelah perubahan.
     */
    public function setAutoRenew(string $domain, bool $enable): bool
    {
        $this->call($enable ? 'addAutoRenewal' : 'removeAutoRenewal', ['domain' => $domain]);

        unset($this->detailsCache[mb_strtolower(trim($domain))]);

        return true;
    }

    /**
     * Daftar domain mentah dari `listDomains` (di-cache per instance).
     *
     * @return list<mixed>
     */
    private function rawDomains(): array
    {
        if ($this->domainCache !== null) {
            return $this->domainCache;
        }

        $reply = $this->call('listDomains');
        $domains = $reply['domains'] ?? [];

        return $this->domainCache = is_array($domains) ? array_values($domains) : [];
    }

    /**
     * Panggil satu operasi API NameSilo dan kembalikan node `reply`.
     *
     * @param  array<string, scalar>  $params
     * @return array<string, mixed>
     */
    private function call(string $operation, array $params = []): array
    {
        if (! $this->isConfigured()) {
            throw ProviderNotConfigured::for($this->provider->name, static::key());
        }

        $query = array_merge([
            'version' => self::API_VERSION,
            'type' => 'json',
            'key' => (string) $this->credential('api_key'),
        ], $params);

        try {
            $response = $this->request()->get($this->baseUrl().'/'.$operation, $query);
        } catch (ConnectionException) {
            throw NameSiloRequestFailed::unreachable($operation);
        }

        if ($response->failed()) {
            throw NameSiloRequestFailed::httpError($operation, $response->status());
        }

        $payload = $response->json();
        $reply = is_array($payload) ? ($payload['reply'] ?? null) : null;

        if (! is_array($reply)) {
            throw NameSiloRequestFailed::invalidResponse($operation);
        }

        // NameSilo membalas error sebagai HTTP 200 + reply.code != 300 (dan `code`
        // bertipe string pada error), jadi harus diperiksa eksplisit.
        $code = $reply['code'] ?? null;

        if (! $this->isSuccessCode($code)) {
            throw NameSiloRequestFailed::apiError($operation, $code ?? 0, (string) ($reply['detail'] ?? ''));
        }

        return $reply;
    }

    private function isSuccessCode(mixed $code): bool
    {
        return is_numeric($code) && (int) $code === self::CODE_SUCCESS;
    }

    private function request(): PendingRequest
    {
        return Http::acceptJson()
            ->timeout(max(1, (int) $this->credential('timeout', 20)));
    }

    private function baseUrl(): string
    {
        $base = trim((string) $this->credential('base_url', self::DEFAULT_BASE_URL));

        return rtrim($base !== '' ? $base : self::DEFAULT_BASE_URL, '/');
    }

    private function enrichDetails(): bool
    {
        return (bool) $this->credential('enrich_details', true);
    }

    /**
     * Detail domain, tapi kegagalan per-domain TIDAK menggagalkan seluruh daftar —
     * hanya dikembalikan null agar baris lain tetap tampil.
     *
     * @return array<string, mixed>|null
     */
    private function detailsOrNull(string $domain): ?array
    {
        try {
            return $this->details($domain);
        } catch (NameSiloRequestFailed $e) {
            // Jangan gagalkan seluruh daftar hanya karena satu domain bermasalah.
            return null;
        }
    }

    /**
     * @param  array<string, mixed>  $reply
     * @return array{
     *     domain: string,
     *     status: string,
     *     auto_renew: ?bool,
     *     locked: ?bool,
     *     private: ?bool,
     *     created: ?string,
     *     expires: ?string,
     *     nameservers: list<string>
     * }
     */
    private function normalizeDetails(string $domain, array $reply): array
    {
        return [
            'domain' => $domain,
            'status' => $this->normalizeStatus(trim((string) ($reply['status'] ?? ''))),
            'auto_renew' => $this->yesNo($reply['auto_renew'] ?? null),
            'locked' => $this->yesNo($reply['locked'] ?? null),
            'private' => $this->yesNo($reply['private'] ?? null),
            'created' => $this->stringOrNull($reply['created'] ?? null),
            'expires' => $this->stringOrNull($reply['expires'] ?? null),
            'nameservers' => $this->normalizeNameservers($reply['nameservers'] ?? null),
        ];
    }

    /**
     * NameSilo mengembalikan `nameservers` sebagai daftar objek
     * `{nameserver, position}`; bentuk lama `[[...]]` juga tolerated.
     *
     * @return list<string>
     */
    private function normalizeNameservers(mixed $nameservers): array
    {
        if (! is_array($nameservers)) {
            return [];
        }

        $normalized = [];

        foreach ($nameservers as $entry) {
            $value = is_array($entry) ? ($entry['nameserver'] ?? null) : $entry;

            if (is_string($value) && trim($value) !== '') {
                $normalized[] = trim($value);
            }
        }

        return $normalized;
    }

    /** Petakan status NameSilo ke bentuk netral yang dipakai UI CRM. */
    private function normalizeStatus(?string $status): string
    {
        $status = mb_strtolower(trim((string) $status));

        return match (true) {
            $status === '' => 'active',                       // detail tak diambil → asumsikan aktif
            str_contains($status, 'expired') => 'expired',
            str_contains($status, 'suspend') => 'suspended',
            str_contains($status, 'transfer') => 'transfer',
            str_contains($status, 'pending') => 'pending',
            str_contains($status, 'active') => 'active',
            default => $status,
        };
    }

    private function parseDate(mixed $value): ?CarbonInterface
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        try {
            return CarbonImmutable::parse(trim($value));
        } catch (\Throwable) {
            return null;   // format tak dikenal → null, jangan gagalkan
        }
    }

    private function stringOrNull(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value === '' || mb_strtoupper($value) === 'N/A' ? null : $value;
    }

    private function yesNo(mixed $value): ?bool
    {
        if (is_bool($value)) {
            return $value;
        }

        if (! is_string($value)) {
            return null;
        }

        return match (mb_strtolower(trim($value))) {
            'yes', 'y', 'true', '1' => true,
            'no', 'n', 'false', '0' => false,
            default => null,
        };
    }
}
