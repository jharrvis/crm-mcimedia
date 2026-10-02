<?php

namespace App\Domains\Hestia\Console\Commands;

use App\Domains\Hestia\Models\HestiaServer;
use App\Domains\Hestia\Models\HestiaSyncLog;
use App\Domains\Hestia\Services\HestiaSyncOrchestrator;
use Illuminate\Console\Command;

/**
 * Sinkronisasi akun hosting/domain dari HestiaCP ke CRM (F3-1, multi-server F4-12).
 * Read-only terhadap Hestia: hanya perintah `v-list*` yang dikirim.
 *
 * Tanpa opsi: menyinkronkan semua server aktif di `hestia_servers` — atau
 * konfigurasi environment (F3-1) bila belum ada server aktif. Kegagalan satu
 * server tidak menghentikan server lain.
 */
class HestiaSyncCommand extends Command
{
    protected $signature = 'hestia:sync
                            {--server= : Sinkronkan hanya satu server (kode atau nama Hestia)}
                            {--list : Tampilkan daftar server terdaftar lalu keluar}';

    protected $description = 'Tarik akun hosting/domain dari HestiaCP ke CRM (read-only, idempotent)';

    public function handle(HestiaSyncOrchestrator $orchestrator): int
    {
        if ($this->option('list')) {
            return $this->listServers($orchestrator);
        }

        $only = $this->option('server');

        if (is_string($only) && trim($only) !== '') {
            return $this->syncOne($orchestrator, trim($only));
        }

        return $this->syncAll($orchestrator);
    }

    private function listServers(HestiaSyncOrchestrator $orchestrator): int
    {
        $servers = HestiaServer::query()->orderBy('name')->get();

        if ($servers->isEmpty()) {
            $this->warn('Belum ada server HestiaCP terdaftar.');
            $this->line('Sinkronisasi akan memakai konfigurasi environment (HESTIA_*).');

            return self::SUCCESS;
        }

        $rows = $servers->map(fn (HestiaServer $s) => [
            $s->code,
            $s->name,
            $s->is_active ? 'aktif' : 'nonaktif',
            $s->isConfigured() ? 'ada' : 'KOSONG',
            $s->last_sync_at?->format('d/m/Y H:i') ?? '—',
        ])->all();

        $this->table(['Kode', 'Nama', 'Status', 'Kredensial', 'Sync terakhir'], $rows);

        return self::SUCCESS;
    }

    private function syncOne(HestiaSyncOrchestrator $orchestrator, string $needle): int
    {
        $server = HestiaServer::query()
            ->where('code', $needle)
            ->orWhere('name', $needle)
            ->first();

        if (! $server instanceof HestiaServer) {
            $this->error("Server Hestia \"{$needle}\" tidak ditemukan.");

            return self::FAILURE;
        }

        if (! $server->is_active) {
            $this->warn("Server \"{$server->name}\" berstatus nonaktif — sinkron tetap dijalankan sesuai permintaan.");
        }

        return $this->report($orchestrator->syncServer($server), $server->name);
    }

    private function syncAll(HestiaSyncOrchestrator $orchestrator): int
    {
        $logs = $orchestrator->syncAll(function (HestiaSyncLog $log, ?HestiaServer $server) {
            $label = $server?->name ?? 'Environment (.env)';

            if (! $log->isSuccess()) {
                $this->warn("[{$label}] gagal: ".($log->message ?? 'tidak diketahui'));

                return;
            }

            $this->info("[{$label}] {$log->pulled} ditarik, {$log->created} baru, {$log->updated} diperbarui, {$log->deactivated} dinonaktifkan.");

            if ($log->unmapped > 0) {
                $this->warn("[{$label}] {$log->unmapped} akun belum dipetakan — buka /hestia untuk memetakan manual.");
            }
        });

        $summary = $orchestrator->summarize($logs);

        $this->info(sprintf(
            'Sinkronisasi selesai: %d sumber, %d sukses, %d gagal (total %d ditarik, %d baru, %d diperbarui, %d dinonaktifkan).',
            $summary['total'],
            $summary['succeeded'],
            $summary['failed'],
            $summary['pulled'],
            $summary['created'],
            $summary['updated'],
            $summary['deactivated'],
        ));

        return $summary['failed'] > 0 ? self::FAILURE : self::SUCCESS;
    }

    private function report(HestiaSyncLog $log, string $label): int
    {
        if (! $log->isSuccess()) {
            $this->error("Sinkronisasi Hestia gagal [{$label}]: ".($log->message ?? 'tidak diketahui'));

            return self::FAILURE;
        }

        $this->info("Sinkronisasi Hestia selesai [{$label}] — {$log->pulled} akun ditarik, {$log->created} baru, {$log->updated} diperbarui, {$log->deactivated} dinonaktifkan.");

        if ($log->unmapped > 0) {
            $this->warn("{$log->unmapped} akun belum dipetakan ke klien — buka /hestia untuk memetakan manual.");
        }

        return self::SUCCESS;
    }
}
