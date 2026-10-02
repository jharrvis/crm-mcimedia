<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Paket, kuota, dan status suspend per akun Hestia (F4-13).
 *
 * Kolom ini adalah pecahan dari payload yang SUDAH tersimpan di `hestia_accounts.raw`
 * (hasil F3-1), jadi tidak ada panggilan API baru dan tidak ada perubahan pada
 * sifat read-only sinkronisasi:
 *
 *   - `disk_used`      ← `U_DISK`   (MB, dari `v-list-web-domains` — `du -shm`)
 *   - `suspended`      ← `SUSPENDED` (flag level web domain)
 *   - `user_suspended` ← `SUSPENDED` (flag level akun Hestia, dari `v-list-users`)
 *
 * `disk_quota` TIDAK ada di payload web domain: kuota paket hanya ada di level
 * user (`DISK_QUOTA`). Nilai 0 = tanpa batas (konvensi Hestia) dan disimpan
 * apa adanya; pembacaan "tanpa batas" dilakukan di model/UI, bukan di DB.
 *
 * `plan` (Paket/PACKAGE) sudah ada sejak F3-1 dan tidak diduplikasi di sini.
 *
 * CATATAN PENTING: `user_suspended` TIDAK bisa di-backfill, karena payload user
 * tidak pernah disimpan di `raw` (yang berisi payload web domain). Baris lama
 * karena itu mendapat nilai default `false` sampai sinkronisasi berikutnya
 * dijalankan. Jadi setelah deploy, JALANKAN SEKALI `php artisan hestia:sync`
 * (atau tunggu jadwal 06:30) sebelum memercayai angka "Suspend" di UI.
 * `down()` juga lossy secara desain: backfill tidak dapat direkonstruksi dari
 * kolom dedicated tanpa memanggil Hestia lagi.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('hestia_accounts', function (Blueprint $table) {
            $table->unsignedInteger('disk_used')->nullable()->after('plan');
            $table->unsignedInteger('disk_quota')->nullable()->after('disk_used');
            $table->boolean('suspended')->default(false)->after('status');
            $table->boolean('user_suspended')->default(false)->after('suspended');
        });

        $this->backfillFromRaw();
    }

    /**
     * Pecah payload `raw` yang sudah ada menjadi kolom baru.
     *
     * Dijalankan per-batch (chunk) supaya aman untuk tabel yang sudah besar,
     * dan dibungkus try/catch: kegagalan backfill tidak boleh menggagalkan
     * migrasi — data akan terisi penuh pada sinkronisasi berikutnya.
     */
    private function backfillFromRaw(): void
    {
        try {
            // Sengaja memakai query builder + `hestia_accounts` literal, bukan
            // model: migrasi lama tidak boleh ikut breakage bila model berubah.
            DB::table('hestia_accounts')
                ->select(['id', 'raw'])
                ->whereNotNull('raw')
                ->orderBy('id')
                ->chunk(200, function ($rows): void {
                    foreach ($rows as $row) {
                        $raw = is_string($row->raw) ? json_decode($row->raw, true) : $row->raw;

                        if (! is_array($raw)) {
                            continue;
                        }

                        $changes = [];

                        $diskUsed = self::toMegabytes($raw['U_DISK'] ?? null);
                        if ($diskUsed !== null) {
                            $changes['disk_used'] = $diskUsed;
                        }

                        $suspended = self::isYes($raw['SUSPENDED'] ?? null);
                        if ($suspended !== null) {
                            $changes['suspended'] = $suspended;
                        }

                        // Tidak ada yang bisa di-backfill → jangan sentuh baris ini.
                        if ($changes === []) {
                            continue;
                        }

                        DB::table('hestia_accounts')
                            ->where('id', $row->id)
                            ->update($changes);
                    }
                });
        } catch (Throwable $e) {
            report($e);
        }
    }

    /**
     * Ubah nilai Hestia menjadi MB bulat, atau null bila tidak terisi/aneh.
     *
     * Hestia mengirim angka sebagai string; `du -shm` bisa menghasilkan "1.2G"
     * pada locale tertentu, jadi satuan GB pun dinormalisasi ke MB.
     */
    private static function toMegabytes(mixed $value): ?int
    {
        if (is_int($value) || is_float($value)) {
            return max(0, (int) round((float) $value));
        }

        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        if ($value === '' || ! is_numeric(rtrim($value, 'GgMmKk'))) {
            return null;
        }

        $unit = strtolower(substr($value, -1));
        $number = (float) rtrim($value, 'GgMmKk');

        $megabytes = match ($unit) {
            'g' => $number * 1024,
            'm' => $number,
            'k' => $number / 1024,
            default => $number,
        };

        return max(0, (int) round($megabytes));
    }

    /** Flag Hestia: "yes" = true, "no"/kosong = false, null = tidak diketahui. */
    private static function isYes(mixed $value): ?bool
    {
        if (! is_string($value)) {
            return null;
        }

        $value = mb_strtolower(trim($value));

        if ($value === '') {
            return null;
        }

        return $value === 'yes' || $value === 'true' || $value === '1';
    }

    public function down(): void
    {
        Schema::table('hestia_accounts', function (Blueprint $table) {
            $table->dropColumn(['disk_used', 'disk_quota', 'suspended', 'user_suspended']);
        });
    }
};
