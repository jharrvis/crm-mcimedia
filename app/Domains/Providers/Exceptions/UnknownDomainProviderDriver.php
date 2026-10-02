<?php

namespace App\Domains\Providers\Exceptions;

use RuntimeException;

/**
 * Dilempar saat kunci driver tidak terdaftar di registry (F4-5).
 */
class UnknownDomainProviderDriver extends RuntimeException
{
    public static function forKey(string $key): self
    {
        return new self("Driver provider domain tidak dikenal: \"{$key}\".");
    }

    public static function forClass(string $class): self
    {
        return new self("Kelas \"{$class}\" bukan DomainProviderDriver yang valid.");
    }
}
