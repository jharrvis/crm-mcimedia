<?php

namespace App\Domains\Hestia\Http\Controllers;

use App\Domains\Hestia\Http\Requests\HestiaServerRequest;
use App\Domains\Hestia\Models\HestiaAccount;
use App\Domains\Hestia\Models\HestiaServer;
use App\Domains\Hestia\Models\HestiaSyncBatch;
use App\Domains\Hestia\Models\HestiaSyncLog;
use App\Domains\Hestia\Services\HestiaBatchSyncService;
use App\Domains\Hestia\Services\HestiaClient;
use App\Domains\Hestia\Services\HestiaSyncOrchestrator;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * CRUD server HestiaCP (F4-12).
 *
 * Kredensial TIDAK pernah dikirim balik ke form: `HestiaServer::credentials`
 * memakai cast `encrypted:array` dan form hanya menampilkan field non-rahasia.
 * Halaman ini tidak pernah memanggil API Hestia — hanya action `test` yang
 * melakukan probe read-only (`v-list-users`).
 */
class HestiaServerController extends Controller
{
    public function index()
    {
        return view('hestia.servers.index', [
            'servers' => HestiaServer::query()
                ->withCount(['accounts', 'syncLogs'])
                ->orderBy('name')
                ->get(),
            'environmentEnabled' => (bool) config('crm.hestia.enabled', false),
            'environmentConfigured' => $this->environmentConfigured(),
        ]);
    }

    public function create()
    {
        return view('hestia.servers.create', [
            'server' => new HestiaServer([
                'port' => 8083,
                'scheme' => 'https',
                'verify_ssl' => false,
                'timeout' => 30,
                'is_active' => true,
            ]),
        ]);
    }

    public function store(HestiaServerRequest $request)
    {
        $server = HestiaServer::create($request->payload());

        return redirect()->route('hestia.servers.index')
            ->with('success', "Server \"{$server->name}\" tersimpan. Isi kredensial lalu sinkronkan.");
    }

    public function edit(HestiaServer $hestia_server)
    {
        return view('hestia.servers.edit', ['server' => $hestia_server]);
    }

    public function update(HestiaServerRequest $request, HestiaServer $hestia_server)
    {
        $hestia_server->update($request->payload());

        return redirect()->route('hestia.servers.index')
            ->with('success', "Server \"{$hestia_server->name}\" diperbarui.");
    }

    public function destroy(HestiaServer $hestia_server)
    {
        $name = $hestia_server->name;

        // Akun & riwayat TIDAK ikut terhapus (FK nullOnDelete) — prinsip F3-1:
        // data tidak pernah hilang, hanya kehilangan sumbernya.
        $hestia_server->delete();

        return redirect()->route('hestia.servers.index')
            ->with('success', "Server \"{$name}\" dihapus. Akun hasil sinkronisasi tetap disimpan.");
    }

    /**
     * Probe konektivitas read-only: memanggil `v-list-users` lalu melaporkan
     * jumlah user. Tidak menyimpan apa pun; pesan error dijamin tidak memuat
     * kredensial (lihat HestiaApiException).
     */
    public function test(HestiaServer $hestia_server)
    {
        if (! config('crm.hestia.enabled', false)) {
            return redirect()->route('hestia.servers.index')
                ->with('error', 'Sinkronisasi dinonaktifkan global (HESTIA_ENABLED=false) — uji koneksi dilewati.');
        }

        if (! $hestia_server->isConfigured()) {
            return redirect()->route('hestia.servers.index')
                ->with('error', "Kredensial server \"{$hestia_server->name}\" belum lengkap (butuh access/secret key atau user+password).");
        }

        try {
            $users = (new HestiaClient($hestia_server->toClientConfig()))->users();

            return redirect()->route('hestia.servers.index')
                ->with('success', "Koneksi ke \"{$hestia_server->name}\" berhasil — ".count($users).' akun Hestia ditemukan.');
        } catch (\Throwable $e) {
            return redirect()->route('hestia.servers.index')
                ->with('error', "Koneksi ke \"{$hestia_server->name}\" gagal: ".$e->getMessage());
        }
    }

    /** Sinkronkan satu server (bukan semua). */
    public function sync(HestiaServer $hestia_server, HestiaSyncOrchestrator $orchestrator)
    {
        $log = $orchestrator->syncServer($hestia_server);

        if (! $log->isSuccess()) {
            return redirect()->route('hestia.servers.index')
                ->with('error', "Sinkronisasi \"{$hestia_server->name}\" gagal: ".($log->message ?? 'tidak diketahui'));
        }

        return redirect()->route('hestia.servers.index')
            ->with('success', "Sinkronisasi \"{$hestia_server->name}\" selesai: {$log->pulled} ditarik, {$log->created} baru, {$log->updated} diperbarui, {$log->deactivated} dinonaktifkan.");
    }

    /**
     * Mulai sesi sinkronisasi BERTAHAP (AJAX) untuk satu server — t_dcccffd9.
     *
     * Beda dengan `sync()` (satu request panjang yang rawan gateway timeout):
     * di sini daftar akun ditarik sekali, lalu browser memanggil `syncBatch()`
     * berulang (default 10 akun per batch). Respons JSON dipakai frontend untuk
     * menggambar progress bar. Form POST lama tetap tersedia untuk pengguna
     * tanpa JavaScript.
     */
    public function syncStart(Request $request, HestiaServer $hestia_server, HestiaBatchSyncService $batches): JsonResponse
    {
        if (! config('crm.hestia.enabled', false)) {
            return response()->json([
                'ok' => false,
                'error' => 'Sinkronisasi dinonaktifkan global (HESTIA_ENABLED=false).',
            ], 422);
        }

        if (! $hestia_server->isConfigured()) {
            return response()->json([
                'ok' => false,
                'error' => "Kredensial server \"{$hestia_server->name}\" belum lengkap (butuh access/secret key atau user+password).",
            ], 422);
        }

        try {
            $batch = $batches->start($hestia_server, $request->integer('batch_size') ?: null);
        } catch (\Throwable $e) {
            // Pesan HestiaApiException dijamin tidak memuat kredensial.
            return response()->json(['ok' => false, 'error' => $e->getMessage()], 422);
        }

        return response()->json(['ok' => true, 'batch' => $this->batchPayload($batch)]);
    }

    /**
     * Proses satu batch berikutnya dari sesi yang dibuat `syncStart()`.
     *
     * Sesi wajib milik server pada URL (batch_id dari server lain ditolak),
     * dan request yang diulang setelah sesi selesai mengembalikan keadaan
     * akhir apa adanya — aman untuk retry.
     */
    public function syncBatch(Request $request, HestiaServer $hestia_server, HestiaBatchSyncService $batches): JsonResponse
    {
        $batch = HestiaSyncBatch::query()
            ->where('hestia_server_id', $hestia_server->id)
            ->find($request->integer('batch_id'));

        if (! $batch instanceof HestiaSyncBatch) {
            return response()->json([
                'ok' => false,
                'error' => 'Sesi sinkronisasi tidak ditemukan untuk server ini.',
            ], 404);
        }

        try {
            $batch = $batches->advance($batch);
        } catch (\Throwable $e) {
            return response()->json(['ok' => false, 'error' => $e->getMessage()], 500);
        }

        return response()->json(['ok' => true, 'batch' => $this->batchPayload($batch)]);
    }

    /**
     * Bentuk JSON progres sesi batch untuk frontend.
     *
     * Tidak memuat data kredensial: hanya hitungan, nama server, pesan hasil,
     * dan daftar akun gagal (nama user + pesan error).
     */
    private function batchPayload(HestiaSyncBatch $batch): array
    {
        $total = max(0, (int) $batch->total_users);
        $processed = min($total, max(0, (int) $batch->processed_users));

        return [
            'id' => $batch->id,
            'status' => $batch->status,
            'done' => ! $batch->isRunning(),
            'batch_size' => (int) $batch->batch_size,
            'total_users' => $total,
            'processed_users' => $processed,
            'percent' => $total > 0 ? (int) round($processed / $total * 100) : 100,
            'server' => [
                'id' => $batch->hestia_server_id,
                'name' => $batch->server?->name,
            ],
            'totals' => [
                'pulled' => (int) $batch->pulled,
                'created' => (int) $batch->created,
                'updated' => (int) $batch->updated,
                'deactivated' => (int) $batch->deactivated,
                'unmapped' => (int) $batch->unmapped,
                'failed_users' => $batch->failedCount(),
            ],
            'errors' => array_values($batch->errors ?? []),
            'message' => $batch->message,
            'started_at' => $batch->started_at?->toIso8601String(),
            'finished_at' => $batch->finished_at?->toIso8601String(),
        ];
    }

    /** Detail singkat hasil sinkronisasi satu server. */
    public function show(HestiaServer $hestia_server)
    {
        return view('hestia.servers.show', [
            'server' => $hestia_server,
            'accounts' => HestiaAccount::with(['client', 'service'])
                ->where('hestia_server_id', $hestia_server->id)
                ->orderBy('domain')
                ->paginate(25)
                ->withQueryString(),
            'logs' => HestiaSyncLog::where('hestia_server_id', $hestia_server->id)
                ->latest()
                ->limit(10)
                ->get(),
        ]);
    }

    /** True bila konfigurasi environment F3-1 punya host + kredensial. */
    private function environmentConfigured(): bool
    {
        return app(HestiaClient::class)->isConfigured();
    }
}
