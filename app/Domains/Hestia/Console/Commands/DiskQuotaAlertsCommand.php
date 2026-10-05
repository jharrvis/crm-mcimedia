<?php

namespace App\Domains\Hestia\Console\Commands;

use App\Domains\Hestia\Services\DiskQuotaAlertService;
use Illuminate\Console\Command;

/**
 * Alert kuota disk website (t_afef420a).
 *
 * Dijalankan harian via Laravel Scheduler (setelah `hestia:sync` mengisi
 * ulang angka `disk_used`/`disk_quota`). Command ini hanya mengevaluasi data
 * yang SUDAH tersinkron — tidak memanggil API Hestia sendiri, sehingga aman
 * dijalankan kapan saja dan tetap read-only terhadap Hestia.
 *
 * Aturan ambang & idempotensi ada di {@see DiskQuotaAlertService}.
 */
class DiskQuotaAlertsCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'crm:disk-quota-alerts';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Periksa kuota disk akun Hestia dan buat alert: >=80% warning, >=90% kritis';

    public function handle(DiskQuotaAlertService $service): int
    {
        $stats = $service->run();

        $this->info(sprintf(
            'Alert kuota disk selesai: %d akun diperiksa, %d alert baru, %d pulih/turun level, %d dilewati.',
            $stats['checked'],
            $stats['created'],
            $stats['recovered'],
            $stats['skipped'],
        ));

        return Command::SUCCESS;
    }
}