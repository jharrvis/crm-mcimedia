<?php

namespace App\Domains\Hestia\Services;

use App\Domains\Hestia\Exceptions\HestiaApiException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Klien HTTP tipis untuk API HestiaCP (F3-1).
 *
 * SIFAT: READ-ONLY. Hanya perintah berawalan `v-list` yang diizinkan — kelas ini
 * menolak perintah lain sebelum request dikirim, sehingga sinkronisasi tidak
 * pernah membuat/mengubah/menghapus akun di Hestia.
 *
 * Kredensial 100% dari config/env (`crm.hestia.*`) dan dikirim sebagai body
 * POST (bukan query string) agar tidak bocor ke log akses server. Kelas ini
 * hanya mencatat nama perintah ke log, tidak pernah parameter kredensial.
 */
class HestiaClient
{
    private string $scheme;

    private string $host;

    private int $port;

    private bool $verifySsl;

    private int $timeout;

    private string $user;

    private string $password;

    private string $accessKey;

    private string $secretKey;

    public function __construct()
    {
        $config = config('crm.hestia', []);

        $this->scheme = (string) ($config['scheme'] ?? 'https');
        $this->host = (string) ($config['host'] ?? '');
        $this->port = (int) ($config['port'] ?? 8083);
        $this->verifySsl = (bool) ($config['verify_ssl'] ?? false);
        $this->timeout = (int) ($config['timeout'] ?? 30);
        $this->user = (string) ($config['user'] ?? '');
        $this->password = (string) ($config['password'] ?? '');
        $this->accessKey = (string) ($config['access_key'] ?? '');
        $this->secretKey = (string) ($config['secret_key'] ?? '');
    }

    /** True bila host & salah satu metode autentikasi sudah diisi. */
    public function isConfigured(): bool
    {
        if ($this->host === '') {
            return false;
        }

        $hasAccessKey = $this->accessKey !== '' && $this->secretKey !== '';
        $hasPassword = $this->user !== '' && $this->password !== '';

        return $hasAccessKey || $hasPassword;
    }

    /**
     * Daftar user Hestia. Mengembalikan array keyed by username.
     * Contoh bentuk: ['mcimedia' => ['PACKAGE' => 'default', 'SUSPENDED' => 'no', ...], ...]
     *
     * @return array<string, array<string, mixed>>
     */
    public function users(): array
    {
        return $this->call('v-list-users', ['json']);
    }

    /**
     * Daftar web domain milik satu user. Mengembalikan array keyed by domain.
     * Contoh bentuk: ['contoh.com' => ['IP' => '...', 'DATE' => '2025-10-27', 'SUSPENDED' => 'no', ...], ...]
     *
     * @return array<string, array<string, mixed>>
     */
    public function webDomains(string $user): array
    {
        return $this->call('v-list-web-domains', [$user, 'json']);
    }

    /**
     * Kirim satu perintah `v-list*` ke endpoint API dan kembalikan hasil JSON.
     *
     * @param  list<string>  $args
     * @return array<string, mixed>
     */
    private function call(string $command, array $args = []): array
    {
        if (! str_starts_with($command, 'v-list')) {
            // Pertahanan berlapis: jangan pernah mengirim perintah mutasi.
            throw new \InvalidArgumentException("Perintah Hestia tidak diizinkan (read-only): {$command}");
        }

        if (! $this->isConfigured()) {
            throw HestiaApiException::notConfigured();
        }

        $payload = ['cmd' => $command, 'returncode' => 'yes'];
        foreach (array_values($args) as $index => $arg) {
            $payload['arg'.($index + 1)] = (string) $arg;
        }

        if ($this->accessKey !== '' && $this->secretKey !== '') {
            // Access/secret key (disarankan, Hestia >= 1.6).
            $payload['hash'] = $this->accessKey.':'.$this->secretKey;
        } else {
            // Autentikasi user/password (legacy).
            $payload['user'] = $this->user;
            $payload['password'] = $this->password;
        }

        // Catat hanya nama perintah & jumlah argumen — JANGAN parameter kredensial.
        Log::debug('Hestia API request', ['cmd' => $command, 'args' => count($args)]);

        $response = $this->request()->post($this->endpoint(), $payload);

        if ($response->failed()) {
            throw HestiaApiException::httpError($command, $response->status());
        }

        return $this->parse($command, $response->body());
    }

    private function request(): PendingRequest
    {
        return Http::withOptions(['verify' => $this->verifySsl])
            ->asForm()
            ->timeout($this->timeout)
            ->acceptJson();
    }

    private function endpoint(): string
    {
        $host = rtrim($this->host, '/');

        // Terima host tanpa skema ("panel.example.com") maupun dengan skema.
        if (str_contains($host, '://')) {
            return $host.'/api/';
        }

        return $this->scheme.'://'.$host.':'.$this->port.'/api/';
    }

    /**
     * Parse respons Hestia. Bila `returncode=yes`, baris pertama adalah kode
     * kembalian (0 = sukses) diikuti output pada baris berikutnya.
     *
     * @return array<string, mixed>
     */
    private function parse(string $command, string $body): array
    {
        $body = trim($body);

        // Deteksi baris kode kembalian opsional: "<kode>\n<output>".
        if (preg_match('/^(\d+)\r?\n(.*)$/s', $body, $matches)) {
            $code = (int) $matches[1];
            $body = trim($matches[2]);

            if ($code !== 0) {
                throw HestiaApiException::commandFailed($command, $code, $body);
            }
        }

        if ($body === '') {
            return [];
        }

        $decoded = json_decode($body, true);

        if (! is_array($decoded)) {
            throw HestiaApiException::invalidResponse($command);
        }

        return $decoded;
    }
}
