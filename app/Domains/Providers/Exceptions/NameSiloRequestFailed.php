<?php

namespace App\Domains\Providers\Exceptions;

use Illuminate\Support\Str;

/**
 * Kegagalan pemanggilan API NameSilo (F4-7).
 *
 * Karakteristik API NameSilo yang membentuk kelas ini:
 *   - Semua operasi WAJIB via GET dan parameter (termasuk `key`) berada di query
 *     string → pesan exception TIDAK BOLEH memuat URL, karena URL memuat API key.
 *   - Kesalahan API biasanya dikembalikan sebagai HTTP 200 dengan
 *     `reply.code` != 300, bukan HTTP error status.
 *
 * Karena itu setiap constructor di sini menerima HANYA nama operasi, kode, dan
 * pesan aman dari penyedia — tidak pernah URL/query/exception asli.
 */
class NameSiloRequestFailed extends ProviderApiException
{
    /**
     * @param  int|null  $apiCode  kode `reply.code` dari NameSilo (null bila
     *                             kegagalan terjadi sebelum/selepas parsing)
     */
    private function __construct(
        string $message,
        private readonly ?int $apiCode = null,
    ) {
        parent::__construct($message);
    }

    /** Kode balasan NameSilo; null bila kegagalan di luar layer reply. */
    public function apiCode(): ?int
    {
        return $this->apiCode;
    }

    /**
     * Balasan API dengan `reply.code` selain 300 (HTTP tetap 200).
     * `detail` dari NameSilo aman ditampilkan (tidak memuat kredensial).
     */
    public static function apiError(string $operation, int|string $code, string $detail = ''): self
    {
        return new self(
            sprintf(
                'Permintaan NameSilo "%s" gagal: kode %s%s',
                $operation,
                (string) $code,
                $detail !== '' ? ' — '.Str::limit($detail, 200) : '',
            ),
            is_numeric($code) ? (int) $code : null,
        );
    }

    /** Error HTTP (koneksi terputus, 5xx, dll). */
    public static function httpError(string $operation, int $status): self
    {
        return new self(sprintf(
            'Permintaan NameSilo "%s" gagal: HTTP %d.',
            $operation,
            $status,
        ));
    }

    /** Jaringan gagal/tidak dapat dihubungi (tanpa URL demi keamanan). */
    public static function unreachable(string $operation): self
    {
        return new self(sprintf(
            'Tidak dapat menghubungi API NameSilo untuk "%s": koneksi timeout atau gagal.',
            $operation,
        ));
    }

    /** Respons bukan JSON valid atau tidak punya node `reply`. */
    public static function invalidResponse(string $operation): self
    {
        return new self(sprintf(
            'Respons API NameSilo untuk "%s" tidak dapat dibaca (bukan JSON yang valid).',
            $operation,
        ));
    }
}
