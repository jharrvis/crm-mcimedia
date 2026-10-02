<?php

namespace Tests\Feature\Providers;

use App\Domains\Providers\Contracts\DomainInfo;
use App\Domains\Providers\Drivers\AbstractDomainProviderDriver;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * Driver palsu untuk tes (F4-5). Membuktikan bahwa menambah penyedia baru
 * cukup lewat satu kelas + pendaftaran di config — tanpa perubahan skema
 * `domain_providers` maupun controller/UI.
 */
class FakeDomainProviderDriver extends AbstractDomainProviderDriver
{
    public static function key(): string
    {
        return 'fake';
    }

    public static function label(): string
    {
        return 'Fake Provider';
    }

    public static function credentialFields(): array
    {
        return [
            'token' => ['label' => 'Token', 'type' => 'text', 'required' => true],
        ];
    }

    public function listDomains(): array
    {
        return [
            new DomainInfo(
                domain: 'fake-domain.test',
                expiresAt: CarbonImmutable::now()->addDays(10),
                status: 'active',
                raw: [],
            ),
        ];
    }

    /** Kredensial yang benar-benar diterima driver (untuk asersi tes). */
    public function receivedToken(): string
    {
        return (string) $this->credential('token');
    }

    public function getExpiry(string $domain): ?CarbonInterface
    {
        return $domain === 'fake-domain.test' ? CarbonImmutable::now()->addDays(10) : null;
    }
}
