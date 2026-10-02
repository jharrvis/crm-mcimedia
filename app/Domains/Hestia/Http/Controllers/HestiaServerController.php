<?php

namespace App\Domains\Hestia\Http\Controllers;

use App\Domains\Hestia\Http\Requests\HestiaServerRequest;
use App\Domains\Hestia\Models\HestiaAccount;
use App\Domains\Hestia\Models\HestiaServer;
use App\Domains\Hestia\Models\HestiaSyncLog;
use App\Domains\Hestia\Services\HestiaClient;
use App\Domains\Hestia\Services\HestiaSyncOrchestrator;
use App\Http\Controllers\Controller;

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
