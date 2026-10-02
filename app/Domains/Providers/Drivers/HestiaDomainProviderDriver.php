<?php

namespace App\Domains\Providers\Drivers;

use App\Domains\Hestia\Services\HestiaClient;
use App\Domains\Providers\Contracts\DomainInfo;
use App\Domains\Providers\Exceptions\ProviderNotConfigured;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * Driver penyedia hosting HestiaCP (F4-5).
 *
 * Memakai klien Hestia yang sudah ada (`HestiaClient`) tetapi dengan kredensial
 * TERSIMPAN per provider (`domain_providers.credentials`) — bukan dari config
 * global. Klien Hestia bersifat READ-ONLY (hanya perintah `v-list*`), sehingga
 * driver ini tidak pernah mengubah data di server hosting.
 *
 * Catatan `getExpiry()`: HestiaCP tidak menyediakan tanggal kedaluwarsa
 * registrasi domain lewat `v-list-web-domains`. Nilai dikembalikan hanya bila
 * respons Hestia kebetulan memuat field tanggal (mis. `EXPIRY`/`END_DATE`);
 * selain itu `null` — pemanggil harus memperlakukan null sebagai
 * "tidak tersedia", bukan "tidak ada domain".
 */
class HestiaDomainProviderDriver extends AbstractDomainProviderDriver
{
    /** Kandidat nama field tanggal kedaluwarsa pada respons Hestia. */
    private const EXPIRY_KEYS = ['EXPIRY', 'expiry', 'END_DATE', 'end_date', 'EXPIRATION', 'expires_at'];

    public static function key(): string
    {
        return 'hestia';
    }

    public static function label(): string
    {
        return 'HestiaCP (hosting)';
    }

    public static function credentialFields(): array
    {
        return [
            'host' => ['label' => 'Host panel', 'type' => 'text', 'required' => true, 'help' => 'mis. panel.example.com'],
            'port' => ['label' => 'Port', 'type' => 'number', 'default' => 8083],
            'verify_ssl' => ['label' => 'Verifikasi SSL', 'type' => 'checkbox', 'default' => false],
            'user' => ['label' => 'Username admin/API', 'type' => 'text', 'required' => true],
            'password' => ['label' => 'Password admin', 'type' => 'password', 'secret' => true, 'help' => 'wajib bila access/secret key tidak diisi'],
            'access_key' => ['label' => 'Access key (opsional)', 'type' => 'password', 'secret' => true],
            'secret_key' => ['label' => 'Secret key (opsional)', 'type' => 'password', 'secret' => true],
            'account' => ['label' => 'Akun Hestia', 'type' => 'text', 'required' => true, 'help' => 'username Hestia yang web domain-nya ditarik'],
        ];
    }

    public function listDomains(): array
    {
        $this->ensureConfigured();

        $account = (string) $this->credential('account');
        $raw = $this->client()->webDomains($account);

        $domains = [];

        foreach ($raw as $domain => $data) {
            $data = is_array($data) ? $data : [];

            $domains[] = new DomainInfo(
                domain: (string) $domain,
                expiresAt: $this->extractExpiry($data),
                status: strtolower((string) ($data['SUSPENDED'] ?? 'no')) === 'yes' ? 'suspended' : 'active',
                raw: $data,
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

    private function client(): HestiaClient
    {
        $credentials = $this->credentials();

        return new HestiaClient([
            'scheme' => (string) $this->credential('scheme', 'https'),
            'host' => (string) $this->credential('host', ''),
            'port' => (int) $this->credential('port', 8083),
            'verify_ssl' => (bool) $this->credential('verify_ssl', false),
            'timeout' => (int) $this->credential('timeout', 30),
            'user' => (string) ($credentials['user'] ?? ''),
            'password' => (string) ($credentials['password'] ?? ''),
            'access_key' => (string) ($credentials['access_key'] ?? ''),
            'secret_key' => (string) ($credentials['secret_key'] ?? ''),
        ]);
    }

    private function ensureConfigured(): void
    {
        $hasCredentials = $this->credential('password') !== null
            || ($this->credential('access_key') !== null && $this->credential('secret_key') !== null);

        $complete = $this->credential('host') !== null
            && $this->credential('user') !== null
            && $this->credential('account') !== null
            && $hasCredentials;

        if (! $complete) {
            throw ProviderNotConfigured::for($this->provider->name, static::key());
        }
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function extractExpiry(array $data): ?CarbonInterface
    {
        foreach (self::EXPIRY_KEYS as $key) {
            $value = $data[$key] ?? null;

            if (is_string($value) && trim($value) !== '') {
                try {
                    return CarbonImmutable::parse($value);
                } catch (\Throwable) {
                    // Format tak dikenal — lewati, jangan gagalkan seluruh daftar.
                }
            }
        }

        return null;
    }
}
