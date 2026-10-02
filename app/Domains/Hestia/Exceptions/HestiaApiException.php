<?php

namespace App\Domains\Hestia\Exceptions;

use RuntimeException;

/**
 * Kesalahan saat berkomunikasi dengan API HestiaCP (F3-1).
 *
 * PENTING: pesan exception TIDAK pernah memuat kredensial (user/password/
 * access key) — hanya nama perintah, kode kembalian, dan pesan server.
 */
class HestiaApiException extends RuntimeException
{
    public static function httpError(string $command, int $status): self
    {
        return new self("Hestia API: perintah {$command} gagal — HTTP {$status}.");
    }

    public static function commandFailed(string $command, int $code, string $output): self
    {
        $detail = trim(mb_substr($output, 0, 300));

        return new self("Hestia API: perintah {$command} mengembalikan kode {$code}. {$detail}");
    }

    public static function invalidResponse(string $command): self
    {
        return new self("Hestia API: respons perintah {$command} bukan JSON yang valid.");
    }

    public static function notConfigured(): self
    {
        return new self('Hestia API belum dikonfigurasi — isi HESTIA_HOST dan kredensial di .env server.');
    }
}
