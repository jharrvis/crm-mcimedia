<?php

namespace App\Domains\Providers\Exceptions;

use RuntimeException;

/**
 * Dilempar driver saat kredensial provider belum lengkap (F4-5).
 */
class ProviderNotConfigured extends RuntimeException
{
    public static function for(string $providerName, string $driverKey): self
    {
        return new self("Provider \"{$providerName}\" (driver: {$driverKey}) belum dikonfigurasi lengkap.");
    }
}
