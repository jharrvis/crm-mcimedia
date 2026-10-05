<?php

namespace App\Domains\Security\Console\Commands;

use App\Domains\Security\Services\WpScan\WpScanService;
use Illuminate\Console\Command;

/**
 * Scan WPScan otomatis untuk situs WordPress klien (t_2e555b0b).
 *
 * Dijalankan harian via Laravel Scheduler. Pipeline lengkap (penemuan target
 * dari akun Hestia, deteksi WordPress, scan, insiden, idempotensi) ada di
 * {@see WpScanService} — command ini hanya antarmuka CLI.
 *
 * Read-only terhadap Hestia (hanya membaca tabel sinkronisasi) dan pasif
 * terhadap situs target (tanpa enumerasi user/brute force).
 */
class WpScanCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'crm:wpscan';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Scan WPScan otomatis: temukan situs WordPress dari akun Hestia, pindai, dan catat temuan sebagai insiden keamanan';

    public function handle(WpScanService $service): int
    {
        $stats = $service->run();

        $this->info(sprintf(
            'WPScan selesai: %d target baru, %d terdeteksi WordPress, %d bukan WordPress, %d dipindai, %d temuan baru, %d temuan ditutup, %d error.',
            $stats['discovered'],
            $stats['detected_wp'],
            $stats['detected_non_wp'],
            $stats['scanned'],
            $stats['findings_created'],
            $stats['findings_resolved'],
            $stats['errors'],
        ));

        return Command::SUCCESS;
    }
}