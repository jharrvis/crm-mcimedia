<?php

namespace Tests\Feature\Hestia;

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

/**
 * Helper bersama untuk tes sinkronisasi HestiaCP (F3-1).
 * Tidak ada kredensial asli di sini — semua nilai dummy untuk tes.
 */
trait InteractsWithHestia
{
    protected string $hestiaPassword = 'rahasia-test-123';

    /** @var array<string, array<string, mixed>> */
    protected array $hestiaUsers = [];

    /** @var array<string, array<string, array<string, mixed>>> */
    protected array $hestiaDomains = [];

    protected function configureHestia(bool $enabled = true): void
    {
        config([
            'crm.hestia.enabled' => $enabled,
            'crm.hestia.host' => 'hestia.test',
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
     * Fake API Hestia berbasis perintah (cmd) di body form. Data disimpan pada
     * properti trait sehingga memanggil ulang method ini mengganti isi respons
     * (berguna untuk menguji sync berikutnya di mana sebagian akun hilang).
     *
     * @param  array<string, array<string, mixed>>  $users
     * @param  array<string, array<string, array<string, mixed>>>  $domainsByUser
     */
    protected function fakeHestia(array $users, array $domainsByUser): void
    {
        $this->hestiaUsers = $users;
        $this->hestiaDomains = $domainsByUser;

        Http::fake(function (Request $request) {
            $data = $request->data();
            $cmd = $data['cmd'] ?? '';

            if ($cmd === 'v-list-users') {
                return Http::response(json_encode($this->hestiaUsers), 200);
            }

            if ($cmd === 'v-list-web-domains') {
                $user = $data['arg1'] ?? '';

                return Http::response(json_encode($this->hestiaDomains[$user] ?? []), 200);
            }

            return Http::response('perintah tidak didukung: '.$cmd, 500);
        });
    }
}
