<?php

namespace App\Domains\Providers;

use App\Domains\Providers\Contracts\DomainProviderDriver;
use App\Domains\Providers\Exceptions\UnknownDomainProviderDriver;
use App\Domains\Providers\Models\DomainProvider;

/**
 * Registry driver penyedia domain/hosting (F4-5).
 *
 * Sumber driver: config `crm.domain_providers.drivers` (daftar nama kelas).
 * Kelas tambahan cukup didaftarkan lewat config tersebut — atau
 * `register()` saat runtime (mis. dari paket/plugin atau test) — dan langsung
 * bisa dipakai tanpa mengubah skema database maupun controller/UI.
 */
class DomainProviderRegistry
{
    /** @var array<string, class-string<DomainProviderDriver>> */
    private array $drivers = [];

    public function __construct()
    {
        foreach (config('crm.domain_providers.drivers', []) as $class) {
            if (is_string($class) && $class !== '') {
                $this->register($class);
            }
        }
    }

    /**
     * Daftarkan satu kelas driver. Kelas harus mengimplementasikan
     * DomainProviderDriver; kuncinya diambil dari `::key()`.
     *
     * @param  class-string  $class
     */
    public function register(string $class): void
    {
        if (! is_subclass_of($class, DomainProviderDriver::class)) {
            throw UnknownDomainProviderDriver::forClass($class);
        }

        $this->drivers[$class::key()] = $class;
    }

    /** True bila kunci driver terdaftar. */
    public function has(string $key): bool
    {
        return array_key_exists($key, $this->drivers);
    }

    /**
     * Resolve kelas driver dari kuncinya.
     *
     * @return class-string<DomainProviderDriver>
     */
    public function resolve(string $key): string
    {
        if (! $this->has($key)) {
            throw UnknownDomainProviderDriver::forKey($key);
        }

        return $this->drivers[$key];
    }

    /** Label ramah satu driver; fallback ke kunci bila tidak terdaftar. */
    public function label(string $key): string
    {
        return $this->has($key) ? $this->resolve($key)::label() : $key;
    }

    /** Bangun instance driver untuk sebuah provider. */
    public function make(DomainProvider $provider): DomainProviderDriver
    {
        $class = $this->resolve($provider->driver);

        return new $class($provider);
    }

    /**
     * Daftar driver untuk dropdown UI: `key => label`.
     *
     * @return array<string, string>
     */
    public function options(): array
    {
        $options = [];

        foreach ($this->drivers as $key => $class) {
            $options[$key] = $class::label();
        }

        return $options;
    }

    /**
     * Skema field kredensial satu driver (untuk membangun form dinamis).
     *
     * @return array<string, array<string, mixed>>
     */
    public function credentialFields(string $key): array
    {
        return $this->resolve($key)::credentialFields();
    }

    /** @return list<string> */
    public function keys(): array
    {
        return array_keys($this->drivers);
    }
}
