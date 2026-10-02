<?php

namespace App\Domains\Providers\Exceptions;

use Illuminate\Http\Client\Response;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Dilempar driver saat API penyedia mengembalikan error atau tidak dapat
 * dihubungi (F4-6).
 *
 * Pesan sengaja TIDAK memuat kredensial apa pun (token/Bearer) — hanya status
 * HTTP + pesan singkat dari penyedia — sehingga aman ditampilkan di halaman
 * (lihat DomainProviderController::domains()) maupun dicatat di log.
 */
class ProviderApiException extends RuntimeException
{
    public static function fromResponse(string $providerName, string $driverKey, Response $response): self
    {
        $detail = trim((string) ($response->json('message') ?? ''));

        if ($detail === '') {
            $detail = trim((string) $response->reason());
        }

        return new self(sprintf(
            'Gagal mengambil data dari provider "%s" (driver: %s): HTTP %d%s',
            $providerName,
            $driverKey,
            $response->status(),
            $detail !== '' ? ' '.Str::limit($detail, 200) : '',
        ));
    }

    public static function connectionFailed(string $providerName, string $driverKey): self
    {
        return new self(sprintf(
            'Gagal menghubungi API provider "%s" (driver: %s): koneksi timeout atau gagal.',
            $providerName,
            $driverKey,
        ));
    }
}
