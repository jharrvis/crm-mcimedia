<?php

namespace App\Domains\Providers\Contracts;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * Nilai kembalian satu domain dari DomainProviderDriver::listDomains() (F4-5).
 *
 * Bentuk netral ini membuat UI/consumer tidak perlu tahu API tiap penyedia:
 * driver bertanggung jawab menerjemahkan respons penyedia menjadi DomainInfo.
 */
final readonly class DomainInfo
{
    /**
     * @param  array<string, mixed>  $raw  payload mentah penyedia (audit/debug)
     */
    public function __construct(
        public string $domain,
        public ?CarbonInterface $expiresAt = null,
        public string $status = 'active',
        public array $raw = [],
    ) {}

    public function isExpired(): bool
    {
        return $this->expiresAt !== null && $this->expiresAt->isPast();
    }

    /** Sisa hari menuju kedaluwarsa; null bila tanggal tidak tersedia. */
    public function daysUntilExpiry(): ?int
    {
        if ($this->expiresAt === null) {
            return null;
        }

        return (int) CarbonImmutable::now()->startOfDay()->diffInDays(
            $this->expiresAt->startOfDay(),
            false,
        );
    }
}
