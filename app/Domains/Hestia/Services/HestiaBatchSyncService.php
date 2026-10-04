<?php

namespace App\Domains\Hestia\Services;

use App\Domains\Hestia\Exceptions\HestiaApiException;
use App\Domains\Hestia\Models\HestiaAccount;
use App\Domains\Hestia\Models\HestiaServer;
use App\Domains\Hestia\Models\HestiaSyncBatch;
use App\Domains\Hestia\Models\HestiaSyncLog;
use Illuminate\Support\Carbon as CarbonAlias;
use Illuminate\Support\Facades\Log;

/**
 * Sinkronisasi HestiaCP BERTAHAP untuk tombol Sync di UI (t_dcccffd9).
 *
 * Latar belakang: sinkronisasi satu kali jalan untuk server besar (mis. 59
 * akun) melewati batas waktu nginx/gateway dan berakhir "Gateway Timeout".
 * Kelas ini memecah proses menjadi beberapa request pendek yang dikendalikan
 * browser (AJAX berurutan):
 *
 *   1. `start()`   — tarik daftar user SEKALI (`v-list-users`), buat sesi
 *      `hestia_sync_batches` + satu baris `hestia_sync_logs` (running).
 *   2. `advance()` — proses ≤ `batch_size` user berikutnya (tiap user: tarik
 *      web domain + upsert, lihat `HestiaSyncService::syncUser()`). Counter
 *      hanya maju setelah batch selesai → request yang timeout boleh diulang
 *      tanpa menggandakan pekerjaan (idempotent).
 *   3. Batch terakhir menutup sesi: akun yang tidak terlihat lagi dinonaktifkan
 *      (memakai akumulasi `seen_keys`), log & status terakhir server diperbarui.
 *
 * Semantik kegagalan (mengikuti F4-12): satu akun bermasalah tidak
 * menghentikan sisanya — dicatat sebagai error, akun lain tetap diproses.
 * Sesi berstatus `failed` hanya bila TIDAK ADA satu pun domain yang berhasil
 * ditarik. Penonaktifan akun hilang otomatis DILEWATI bila ada akun gagal
 * ditarik (daftar `seen_keys` tidak lengkap tidak boleh dipakai mematikan akun).
 */
class HestiaBatchSyncService
{
    /**
     * Jumlah akun (user Hestia) per batch. Sepuluh akun ≈ beberapa detik di
     * jaringan normal — aman terhadap timeout proxy 60 dtk yang umum.
     */
    public const DEFAULT_BATCH_SIZE = 10;

    /** Batas atas `batch_size` dari request agar satu request tidak terlalu lama. */
    public const MAX_BATCH_SIZE = 50;

    public function __construct(
        private readonly HestiaSyncService $sync,
    ) {}

    /**
     * Mulai sesi sinkronisasi bertahap untuk satu server.
     *
     * @param  int|null  $batchSize  null = DEFAULT_BATCH_SIZE; nilai > MAX dibatasi.
     *
     * @throws HestiaApiException bila sinkronisasi dinonaktifkan, kredensial
     *                            belum lengkap, atau daftar user gagal ditarik.
     */
    public function start(HestiaServer $server, ?int $batchSize = null): HestiaSyncBatch
    {
        if (! config('crm.hestia.enabled', false)) {
            throw HestiaApiException::notConfigured();
        }

        $client = new HestiaClient($server->toClientConfig());

        if (! $client->isConfigured()) {
            throw HestiaApiException::notConfigured();
        }

        // Daftar user ditarik SEKALI di sini (satu request ringan): total akun
        // sudah diketahui saat request pertama — itulah sumber angka progress
        // bar — dan slice batch selalu stabil walau data di Hestia berubah.
        $users = [];
        foreach ($client->users() as $username => $userData) {
            $users[] = [
                'key' => $username,
                'data' => is_array($userData) ? $userData : [],
            ];
        }

        // Sesi "running" yang tertinggal (mis. tab/browser ditutup di tengah
        // proses) digantikan: hanya boleh ada satu sesi aktif per server.
        HestiaSyncBatch::query()
            ->where('hestia_server_id', $server->id)
            ->where('status', HestiaSyncBatch::STATUS_RUNNING)
            ->get()
            ->each(fn (HestiaSyncBatch $stale) => $this->fail($stale, 'Sesi sinkronisasi sebelumnya belum selesai — dibatalkan karena ada sesi baru dimulai.'));

        $log = HestiaSyncLog::create([
            'hestia_server_id' => $server->id,
            'status' => HestiaSyncLog::STATUS_RUNNING,
            'started_at' => CarbonAlias::now(),
        ]);

        return HestiaSyncBatch::create([
            'hestia_server_id' => $server->id,
            'hestia_sync_log_id' => $log->id,
            'status' => HestiaSyncBatch::STATUS_RUNNING,
            'total_users' => count($users),
            'processed_users' => 0,
            'next_offset' => 0,
            'batch_size' => $this->clampBatchSize($batchSize),
            'users' => $users,
            'seen_keys' => [],
            'errors' => [],
            'pulled' => 0,
            'created' => 0,
            'updated' => 0,
            'deactivated' => 0,
            'unmapped' => 0,
            'started_at' => CarbonAlias::now(),
        ]);
    }

    /**
     * Proses batch berikutnya (dipanggil berulang oleh frontend sampai selesai).
     *
     * Aman dipanggil ulang: sesi yang sudah selesai dikembalikan apa adanya,
     * dan retry request yang timeout hanya melanjutkan dari `next_offset`
     * terakhir yang tersimpan. Penulisan hasil memakai update bersyarat pada
     * `next_offset` sehingga request kembar tidak pernah menghitung counter
     * dua kali (upsert akun sendiri sudah idempotent).
     */
    public function advance(HestiaSyncBatch $batch): HestiaSyncBatch
    {
        if (! $batch->isRunning()) {
            return $batch;
        }

        $server = $batch->server;

        if (! $server instanceof HestiaServer) {
            $this->fail($batch, 'Server Hestia tidak ditemukan (mungkin sudah dihapus).');

            return $batch->refresh();
        }

        if (! config('crm.hestia.enabled', false)) {
            $this->fail($batch, 'Sinkronisasi dinonaktifkan (HESTIA_ENABLED=false) — proses dihentikan di tengah jalan.');

            return $batch->refresh();
        }

        $client = new HestiaClient($server->toClientConfig());

        $slice = array_slice($batch->users ?? [], $batch->next_offset, $batch->batch_size);

        $seenKeys = $batch->seen_keys ?? [];
        $errors = $batch->errors ?? [];
        $pulled = 0;
        $created = 0;
        $updated = 0;

        foreach ($slice as $entry) {
            $key = $entry['key'] ?? '';
            $data = $entry['data'] ?? [];

            try {
                $result = $this->sync->syncUser($client, $server, $key, $data);

                $pulled += $result['pulled'];
                $created += $result['created'];
                $updated += $result['updated'];
                array_push($seenKeys, ...$result['seen_keys']);
            } catch (\Throwable $e) {
                // Satu akun gagal (mis. timeout / payload rusak) tidak boleh
                // menghentikan batch: dicatat, sisa akun tetap diproses.
                $errors[] = [
                    'user' => (string) $key,
                    'message' => $this->limitMessage($e->getMessage()),
                ];
            }
        }

        $processed = $batch->next_offset + count($slice);

        // Update bersyarat (optimistic) — hanya pemilik posisi `next_offset`
        // terakhir yang boleh menulis hasil batch. Bila request kembar sudah
        // memajukan sesi (mis. retry otomatis menyusul, padahal batch pertama
        // tetap selesai di server), hasil ganda dibuang: upsert-nya idempotent,
        // tetapi counter TIDAK boleh dihitung dua kali. Tanpa penjaga ini,
        // audit `pulled/created/updated` bisa membengkak pada timeout langka.
        if ($slice !== []) {
            $applied = HestiaSyncBatch::query()
                ->whereKey($batch->id)
                ->where('status', HestiaSyncBatch::STATUS_RUNNING)
                ->where('next_offset', $batch->next_offset)
                ->update([
                    'next_offset' => $processed,
                    'processed_users' => $processed,
                    'pulled' => $batch->pulled + $pulled,
                    'created' => $batch->created + $created,
                    'updated' => $batch->updated + $updated,
                    'seen_keys' => json_encode($seenKeys),
                    'errors' => json_encode($errors),
                    'updated_at' => CarbonAlias::now(),
                ]);

            if ($applied === 0) {
                return $batch->refresh();
            }
        }

        $batch->refresh();

        if ($batch->isComplete()) {
            $this->finalize($batch);
        }

        return $batch->refresh();
    }

    /**
     * Tutup sesi di batch terakhir: nonaktifkan akun yang hilang (hanya bila
     * aman), tulis hasil akhir ke `hestia_sync_logs`, dan catat status terakhir
     * server. Sesi tidak pernah tinggal berstatus running setelah ini.
     */
    private function finalize(HestiaSyncBatch $batch): void
    {
        $server = $batch->server;
        $failed = $batch->failedCount();

        // Penonaktifan hanya dijalankan dengan daftar seen_keys yang LENGKAP
        // (tidak ada akun gagal) dan ada domain yang benar-benar terlihat —
        // prinsip F3-1/F4-12: kegagalan sementara tidak boleh mematikan akun.
        $deactivated = $failed === 0 && $batch->pulled > 0
            ? $this->sync->deactivateMissing($batch->seen_keys ?? [], $server)
            : 0;

        $unmapped = HestiaAccount::unmapped()->forServer($server)->count();

        // Gagal total (tidak ada satu pun domain ditarik) → status gagal;
        // gagal sebagian tetap sukses dengan catatan (pola F4-12).
        $success = ! ($failed > 0 && $batch->pulled === 0);

        $message = $success
            ? $this->successMessage($batch, $deactivated, $failed)
            : 'Sinkronisasi gagal: tidak ada akun yang berhasil ditarik. Penyebab pertama: '.$this->firstErrorMessage($batch);

        if ($failed > 0 && $batch->pulled > 0) {
            $message .= ' '.$failed.' dari '.$batch->total_users.' akun gagal ditarik ('.$this->failedUsersLabel($batch).'). Penonaktifan akun yang hilang dilewati — jalankan sinkronisasi ulang untuk melengkapinya.';
        }

        if ($unmapped > 0) {
            $message .= ' '.$unmapped.' akun belum dipetakan ke klien.';
        }

        $message = $this->limitMessage($message);
        $finishedAt = CarbonAlias::now();

        $batch->forceFill([
            'status' => $success ? HestiaSyncBatch::STATUS_SUCCESS : HestiaSyncBatch::STATUS_FAILED,
            'deactivated' => $deactivated,
            'unmapped' => $unmapped,
            'message' => $message,
            'finished_at' => $finishedAt,
        ])->save();

        $log = $batch->syncLog;
        if ($log instanceof HestiaSyncLog) {
            $log->update([
                'status' => $success ? HestiaSyncLog::STATUS_SUCCESS : HestiaSyncLog::STATUS_FAILED,
                'pulled' => $batch->pulled,
                'created' => $batch->created,
                'updated' => $batch->updated,
                'deactivated' => $deactivated,
                'unmapped' => $unmapped,
                'message' => $message,
                'finished_at' => $finishedAt,
            ]);
        }

        $server?->recordSyncResult($success, $message);

        Log::info('Sinkronisasi Hestia batch selesai', [
            'server' => $server?->name ?? $batch->hestia_server_id,
            'accounts' => $batch->total_users,
            'failed' => $failed,
            'pulled' => $batch->pulled,
            'created' => $batch->created,
            'updated' => $batch->updated,
            'deactivated' => $deactivated,
            'unmapped' => $unmapped,
        ]);
    }

    /** Tutup sesi sebagai gagal (dipakai saat proses tidak bisa dilanjutkan). */
    private function fail(HestiaSyncBatch $batch, string $message): void
    {
        $message = $this->limitMessage($message);
        $finishedAt = CarbonAlias::now();

        $batch->forceFill([
            'status' => HestiaSyncBatch::STATUS_FAILED,
            'message' => $message,
            'finished_at' => $finishedAt,
        ])->save();

        $log = $batch->syncLog;
        if ($log instanceof HestiaSyncLog && $log->status === HestiaSyncLog::STATUS_RUNNING) {
            $log->update([
                'status' => HestiaSyncLog::STATUS_FAILED,
                'message' => $message,
                'finished_at' => $finishedAt,
            ]);
        }

        $batch->server?->recordSyncResult(false, $message);

        Log::warning('Sinkronisasi Hestia batch dihentikan', [
            'server' => $batch->server?->name ?? $batch->hestia_server_id,
            'message' => $message,
        ]);
    }

    /** Ringkasan sukses yang ditampilkan sebagai hasil akhir di UI. */
    private function successMessage(HestiaSyncBatch $batch, int $deactivated, int $failed): string
    {
        if ($batch->total_users === 0) {
            return 'Sinkronisasi selesai: tidak ada akun Hestia di server ini.';
        }

        $synced = $batch->total_users - $failed;

        if ($batch->pulled === 0) {
            return sprintf('Sinkronisasi selesai: %d akun tersinkron, tetapi tidak ada domain web yang ditemukan.', $synced);
        }

        return sprintf(
            'Sinkronisasi selesai: %d akun tersinkron, %d domain ditarik (%d baru, %d diperbarui%s).',
            $synced,
            $batch->pulled,
            $batch->created,
            $batch->updated,
            $deactivated > 0 ? ", {$deactivated} dinonaktifkan" : '',
        );
    }

    /**
     * Nama akun yang gagal, maksimal 10 nama supaya pesan tetap ringkas.
     * Hanya nama user & pesan error — kredensial tidak pernah masuk ke sini.
     */
    private function failedUsersLabel(HestiaSyncBatch $batch, int $limit = 10): string
    {
        $users = array_map(fn (array $error) => (string) ($error['user'] ?? '?'), $batch->errors ?? []);
        $shown = array_slice($users, 0, $limit);
        $label = implode(', ', $shown);

        return count($users) > $limit ? $label.', … (+'.(count($users) - $limit).' lagi)' : $label;
    }

    private function firstErrorMessage(HestiaSyncBatch $batch): string
    {
        $errors = $batch->errors ?? [];

        return $errors === [] ? 'tidak diketahui' : (string) ($errors[0]['message'] ?? 'tidak diketahui');
    }

    /** Batasi ukuran batch: kosong/nol = default; terlalu besar dipotong ke batas aman. */
    private function clampBatchSize(?int $batchSize): int
    {
        if ($batchSize === null || $batchSize < 1) {
            return self::DEFAULT_BATCH_SIZE;
        }

        return min($batchSize, self::MAX_BATCH_SIZE);
    }

    /** Pesan disimpan ke kolom varchar(1000) — potong aman agar tidak pernah overflow. */
    private function limitMessage(string $message): string
    {
        return mb_strlen($message) > 900 ? mb_substr($message, 0, 897).'…' : $message;
    }
}
