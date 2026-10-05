<?php

namespace App\Domains\Security\Console\Commands;

use App\Domains\Security\Services\FileIntegrity\FileIntegrityService;
use Illuminate\Console\Command;

/**
 * File integrity check via wp-cli (t_a94a2d2c).
 *
 * Memeriksa 10 situs WordPress per run (bergiliran). Dijalankan harian
 * via scheduler — semua situs tercakup dalam siklus bertahap.
 */
class FileIntegrityCommand extends Command
{
    protected $signature = 'crm:file-integrity';

    protected $description = 'Cek integritas file WordPress (wp core verify-checksums), 10 situs per run';

    public function handle(FileIntegrityService $service): int
    {
        $stats = $service->run();

        $this->info(sprintf(
            'File integrity selesai: %d dicek, %d bersih, %d dimodifikasi, %d error.',
            $stats['checked'],
            $stats['clean'],
            $stats['modified'],
            $stats['errors'],
        ));

        return Command::SUCCESS;
    }
}
