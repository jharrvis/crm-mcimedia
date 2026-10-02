<?php

namespace App\Domains\Providers\Contracts;

use App\Domains\Providers\Models\DomainProvider;
use Carbon\CarbonInterface;

/**
 * Kontrak driver penyedia domain/hosting (F4-5).
 *
 * SEMUA driver konkret wajib mengimplementasikan antarmuka ini. Menambah
 * penyedia baru = cukup membuat kelas: `implements DomainProviderDriver`
 * (biasanya lewat AbstractDomainProviderDriver), lalu daftarkan kelasnya di
 * `config/crm.php` → `crm.domain_providers.drivers`. Tidak ada perubahan skema
 * database maupun perubahan pada UI/controller.
 *
 * Metode statis (`key`, `label`, `credentialFields`) mendeskripsikan driver itu
 * sendiri sehingga registry/UI bisa menampilkan & memvalidasi form kredensial
 * secara dinamis, tanpa tahu detail driver.
 */
interface DomainProviderDriver
{
    /** Kunci unik driver, disimpan di kolom `domain_providers.driver`. */
    public static function key(): string;

    /** Nama tampilan driver untuk UI. */
    public static function label(): string;

    /**
     * Skema field kredensial yang dibutuhkan driver ini. UI membangun form dari
     * definisi ini dan FormRequest memvalidasinya secara dinamis.
     *
     * @return array<string, array{
     *     label: string,
     *     type: string,        // text|number|password|textarea|checkbox|select
     *     required?: bool,
     *     secret?: bool,       // nilai tidak pernah ditampilkan ulang (mis. password)
     *     default?: mixed,
     *     help?: string,
     *     options?: array<string, string>
     * }>
     */
    public static function credentialFields(): array;

    /**
     * Daftar domain yang dikelola penyedia.
     *
     * @return list<DomainInfo>
     */
    public function listDomains(): array;

    /**
     * Tanggal kedaluwarsa satu domain; null bila penyedia tidak menyediakannya.
     */
    public function getExpiry(string $domain): ?CarbonInterface;

    /** Model provider yang sedang dipakai driver ini. */
    public function provider(): DomainProvider;
}
