<?php

namespace Tests\Feature\Hestia;

use App\Domains\Hestia\Models\HestiaServer;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

/**
 * Helper bersama untuk tes sinkronisasi HestiaCP multi-server (F4-12).
 *
 * Fake HTTP Hestia bersifat per-host: tiap server (dan mode environment)
 * punya tabel user/domain sendiri, sehingga tes bisa memastikan sync server A
 * benar-benar tidak menyentuh akun server B.
 *
 * Tidak ada kredensial nyata di sini — semua nilai dummy untuk tes.
 */
trait InteractsWithHestiaServers
{
    protected string $serverPassword = 'rahasia-server-test-123';

    /**
     * Data fake per endpoint host.
     *
     * @var array<string, array{users: array<string, array<string, mixed>>, domains: array<string, array<string, array<string, mixed>>>}>
     */
    protected array $hestiaFixtures = [];

    /**
     * Host yang harus mengembalikan HTTP 500 (untuk menguji kegagalan per server).
     *
     * @var array<string, true>
     */
    protected array $hestiaFailingHosts = [];

    /** Aktifkan sinkronisasi global + isi path environment F3-1. */
    protected function configureHestiaEnvironment(bool $enabled = true): void
    {
        config([
            'crm.hestia.enabled' => $enabled,
            'crm.hestia.host' => 'env.test',
            'crm.hestia.port' => 8083,
            'crm.hestia.scheme' => 'https',
            'crm.hestia.verify_ssl' => false,
            'crm.hestia.user' => 'admin',
            'crm.hestia.password' => $this->hestiaPassword,
            'crm.hestia.access_key' => '',
            'crm.hestia.secret_key' => '',
        ]);
    }

    /**
     * Daftarkan fixture Hestia untuk satu host.
     *
     * @param  array<string, array<string, mixed>>  $users
     * @param  array<string, array<string, array<string, mixed>>>  $domainsByUser
     */
    protected function fakeHestiaHost(string $host, array $users, array $domainsByUser = []): void
    {
        $this->hestiaFixtures[$host] = [
            'users' => $users,
            'domains' => $domainsByUser,
        ];
    }

    /** Tandai host sebagai gagal (HTTP 500) saat sync. */
    protected function failHestiaHost(string $host): void
    {
        $this->hestiaFailingHosts[$host] = true;
    }

    /** Aktifkan fake HTTP yang mendispatch respons per host. */
    protected function installHestiaHttpFake(): void
    {
        Http::fake(function (Request $request) {
            $host = parse_url($request->url(), PHP_URL_HOST) ?: '';
            $data = $request->data();
            $cmd = $data['cmd'] ?? '';

            if (isset($this->hestiaFailingHosts[$host])) {
                return Http::response('server tidak sengaja', 500);
            }

            $fixture = $this->hestiaFixtures[$host] ?? null;

            if ($fixture === null) {
                return Http::response('0', 200);
            }

            if ($cmd === 'v-list-users') {
                return Http::response(json_encode($fixture['users']), 200);
            }

            if ($cmd === 'v-list-web-domains') {
                $user = $data['arg1'] ?? '';

                return Http::response(json_encode($fixture['domains'][$user] ?? []), 200);
            }

            // Perintah selain v-list* sengaja TIDAK didukung — bila ada kode
            // yang mengirim mutasi, tes ini gagal dengan pesan yang jelas.
            return Http::response('perintah tidak didukung: '.$cmd, 500);
        });
    }

    /**
     * Buat server HestiaCP siap pakai (aktif + host + kredensial dummy).
     */
    protected function makeServer(string $name, string $code, array $attributes = []): HestiaServer
    {
        return HestiaServer::create(array_merge([
            'name' => $name,
            'code' => $code,
            'host' => $code.'.test',
            'port' => 8083,
            'scheme' => 'https',
            'verify_ssl' => false,
            'timeout' => 30,
            'is_active' => true,
        ], $attributes, [
            'credentials' => ['user' => 'admin', 'password' => $this->serverPassword],
        ]));
    }

    /** Payload web domain Hestia (subset field yang relevan). */
    protected function domainPayload(string $date = '2026-01-01', string $suspended = 'no'): array
    {
        return ['IP' => '203.0.113.10', 'DATE' => $date, 'SUSPENDED' => $suspended];
    }
}
