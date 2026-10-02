<?php

namespace App\Domains\Hestia\Services;

/**
 * Normalisasi nilai kuantitas dari payload HestiaCP (F4-13).
 *
 * Hestia mengirim SEMUA nilai sebagai string dan tidak konsisten soal satuan:
 *
 *   - `U_DISK`      (level web domain) — hasil `du -shm`, jadi MB.
 *   - `DISK_QUOTA`  (level user/paket) — MB, dengan `0` berarti TANPA BATAS.
 *   - `SUSPENDED`   — `"yes"` / `"no"`, ada di KEDUA level.
 *
 * Mengapa nilai tidak bisa dipakai mentah? Karena `"0"` (string) adalah nilai sah untuk
 * kuota tanpa batas, sedangkan `""`/`null` berarti "Hestia tidak melaporkan" —
 * keduanya harus berbeda agar UI tidak menampilkan bar yang menyesatkan.
 * Semua parser di sini karena itu mengembalikan `?int` / `?bool` (null = tidak
 * diketahui) dan TIDAK pernah melempar exception: payload rusak tidak boleh
 * menggagalkan seluruh sinkronisasi.
 *
 * Fakta satuan diambil dari sumber HestiaCP:
 *   bin/v-update-web-domain-disk  → `du -shm` (MB)
 *   bin/v-list-user              → `DISK: $U_DISK/$DISK_QUOTA`
 *   bin/v-add-user               → `DISK_QUOTA=0` diartikan unlimited
 */
final class HestiaQuota
{
    private function __construct() {}

    /**
     * Baca kuota disk paket dari payload user (`v-list-users`).
     *
     * Mengembalikan MB bulat, atau null bila field tidak ada/tidak bisa dibaca.
     * Nilai 0 tetap dikembalikan 0 (artinya "tanpa batas") — keputusan
     * ditampilkan/tidak dipegang `HestiaAccount::hasDiskQuota()`.
     *
     * @param  array<string, mixed>  $userData
     */
    public static function fromUserPayload(array $userData): ?int
    {
        if (! array_key_exists('DISK_QUOTA', $userData)) {
            return null;
        }

        return self::toMegabytes($userData['DISK_QUOTA']);
    }

    /**
     * Ubah nilai Hestia menjadi MB bulat, atau null bila tidak terisi/aneh.
     *
     * Menangani satuan eksplisit ("512", "2G", "1.5M") dan menolak nilai
     * non-numerik. Nilai negatif diklem ke 0.
     */
    public static function toMegabytes(mixed $value): ?int
    {
        if (is_int($value) || is_float($value)) {
            return max(0, (int) round((float) $value));
        }

        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        if ($value === '') {
            return null;
        }

        $unit = strtolower(substr($value, -1));
        $number = rtrim($value, 'GgMmKk');

        if ($number === '' || ! is_numeric($number)) {
            return null;
        }

        $megabytes = match ($unit) {
            'g' => (float) $number * 1024,
            'k' => (float) $number / 1024,
            default => (float) $number,
        };

        return max(0, (int) round($megabytes));
    }

    /**
     * Flag Hestia: `"yes"` = true, `"no"` = false, null = tidak diketahui.
     *
     * Sengaja TIDAK memakai cast boolean PHP: `cast("false", 'bool')` bernilai
     * true, dan string apa pun yang tidak dikenal harus dianggap "tidak
     * diketahui" — bukan "tidak suspend" — supaya sync tidak menebak-nebak
     * dan tidak diam-diam menandai akun aman padahal statusnya tidak pasti.
     */
    public static function isYes(mixed $value): ?bool
    {
        if (is_bool($value)) {
            return $value;
        }

        if (! is_string($value) && ! is_int($value)) {
            return null;
        }

        $value = mb_strtolower(trim((string) $value));

        if (in_array($value, ['yes', 'true', '1'], true)) {
            return true;
        }

        if (in_array($value, ['no', 'false', '0'], true)) {
            return false;
        }

        return null;
    }
}
