<?php

namespace App\Domains\Hestia\Services;

use App\Domains\Hestia\Models\HestiaServer;
use App\Domains\Hestia\Models\HestiaSyncLog;
use Illuminate\Support\Collection;

/**
 * Orkestrator sinkronisasi multi-server (F4-12).
 *
 * Menentukan daftar server yang perlu disinkronkan lalu memanggil
 * `HestiaSyncService` satu per server. Kegagalan satu server TIDAK menghentikan
 * server berikutnya — tiap hasil dikembalikan terpisah supaya command/UI bisa
 * melaporkan hasil per server.
 *
 * Fallback environment: bila belum ada server aktif di tabel `hestia_servers`,
 * sinkronisasi memakai konfigurasi `crm.hestia.*` (path F3-1) agar instalasi lama
 * tetap berjalan tanpa perubahan. Begitu ada server aktif, environment tidak
 * lagi ikut disinkronkan untuk menghindari akun ganda dari sumber yang sama —
 * admin dapat beralih dari UI dengan membuat/mengaktifkan server terkait.
 */
class HestiaSyncOrchestrator
{
    public function __construct(
        private readonly HestiaSyncService $sync,
    ) {}

    /**
     * Server yang akan disinkronkan. Mengembalikan `[null]` bila mode env.
     *
     * @return Collection<int, HestiaServer|null>
     */
    public function targets(): Collection
    {
        $servers = HestiaServer::query()
            ->active()
            ->orderBy('name')
            ->get();

        if ($servers->isEmpty()) {
            // Nol server aktif → kembali ke konfigurasi environment (F3-1).
            return collect([null]);
        }

        return $servers;
    }

    /**
     * Sinkronkan satu server tertentu.
     */
    public function syncServer(HestiaServer $server): HestiaSyncLog
    {
        return $this->sync->sync($server);
    }

    /**
     * Sinkronkan environment (path F3-1), tanpa server terkelola.
     */
    public function syncEnvironment(): HestiaSyncLog
    {
        return $this->sync->sync(null);
    }

    /**
     * Sinkronkan SEMUA server aktif (atau environment bila belum ada server).
     *
     * @param  callable|null  $onEach  dipanggil setelah tiap server: fn (HestiaSyncLog $log, HestiaServer|null $server)
     * @return Collection<int, HestiaSyncLog>
     */
    public function syncAll(?callable $onEach = null): Collection
    {
        return $this->targets()->map(function (?HestiaServer $server) use ($onEach) {
            $log = $this->sync->sync($server);

            if ($onEach !== null) {
                $onEach($log, $server);
            }

            return $log;
        });
    }

    /** Ringkasan hasil sync-all untuk ditampilkan di UI/command. */
    public function summarize(Collection $logs): array
    {
        return [
            'total' => $logs->count(),
            'succeeded' => $logs->filter(fn (HestiaSyncLog $log) => $log->isSuccess())->count(),
            'failed' => $logs->filter(fn (HestiaSyncLog $log) => ! $log->isSuccess())->count(),
            'pulled' => $logs->sum('pulled'),
            'created' => $logs->sum('created'),
            'updated' => $logs->sum('updated'),
            'deactivated' => $logs->sum('deactivated'),
            'unmapped' => $logs->sum('unmapped'),
        ];
    }
}
