<?php

namespace App\Domains\Providers\Drivers;

use App\Domains\Providers\Contracts\DomainProviderDriver;
use App\Domains\Providers\Models\DomainProvider;

/**
 * Basis driver penyedia domain/hosting (F4-5).
 *
 * Menyediakan akses aman ke kredensial provider (array terdekripsi) dan helper
 * pembacaan field, sehingga driver konkret cukup fokus pada pemanggilan API
 * penyedia masing-masing.
 */
abstract class AbstractDomainProviderDriver implements DomainProviderDriver
{
    public function __construct(protected DomainProvider $provider) {}

    public function provider(): DomainProvider
    {
        return $this->provider;
    }

    /** Seluruh kredensial provider (sudah didekripsi oleh cast model). */
    protected function credentials(): array
    {
        $credentials = $this->provider->credentials;

        return is_array($credentials) ? $credentials : [];
    }

    /** Baca satu field kredensial dengan nilai default. */
    protected function credential(string $key, mixed $default = null): mixed
    {
        $value = $this->credentials()[$key] ?? null;

        return ($value === null || $value === '') ? $default : $value;
    }

    /** True bila semua field wajib sudah terisi. */
    protected function isConfigured(): bool
    {
        foreach (static::credentialFields() as $name => $field) {
            if (($field['required'] ?? false) && blank($this->credential($name))) {
                return false;
            }
        }

        return true;
    }
}
