<?php

namespace App\Domains\Hestia\Console\Commands;

use App\Domains\Hestia\Services\HestiaSyncService;
use Illuminate\Console\Command;

/**
 * Sinkronisasi akun hosting/domain dari HestiaCP ke CRM (F3-1).
 * Read-only terhadap Hestia: hanya perintah `v-list*` yang dikirim.
 */
class HestiaSyncCommand extends Command
{
    protected $signature = 'hestia:sync';

    protected $description = 'Tarik akun hosting/domain dari HestiaCP ke CRM (read-only, idempotent)';

    public function handle(HestiaSyncService $sync): int
    {
        $log = $sync->sync();

        if (! $log->isSuccess()) {
            $this->error('Sinkronisasi Hestia gagal: '.($log->message ?? 'tidak diketahui'));

            return self::FAILURE;
        }

        $this->info("Sinkronisasi Hestia selesai — {$log->pulled} akun ditarik, {$log->created} baru, {$log->updated} diperbarui, {$log->deactivated} dinonaktifkan.");

        if ($log->unmapped > 0) {
            $this->warn("{$log->unmapped} akun belum dipetakan ke klien — buka /hestia untuk memetakan manual.");
        }

        return self::SUCCESS;
    }
}
