<?php

namespace App\Domains\Providers\Console\Commands;

use App\Domains\Providers\Models\DomainProvider;
use App\Domains\Providers\Services\ProviderServiceImporter;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Sinkronisasi harian tanggal kedaluwarsa domain dari provider aktif (t_f2ae1c62).
 *
 * Menjalankan ProviderServiceImporter::import() tanpa klien tujuan untuk setiap
 * provider domain yang AKTIF — mode ini idempotent: hanya menyegarkan `end_date`
 * layanan yang sudah dipetakan (lewat kolom `reference`), tidak pernah membuat
 * layanan baru, dan tidak menghapus. Domain baru yang belum dipetakan dilewati
 * dan dilaporkan agar admin memetakannya lewat UI (/domain-providers).
 *
 * Kegagalan satu provider (kredensial salah, API offline, dsb.) dicatat ke log
 * dan TIDAK menghentikan provider lain; ringkasan akhir ditulis ke log Laravel
 * (jumlah di-refresh, dibuat, dilewati, gagal) dan ke output console.
 *
 * Terjadwal harian pukul 06:00 WIB (23:00 UTC) di bootstrap/app.php — sebelum
 * draf invoice perpanjangan (07:30) dan pengingat layanan (08:00/08:15) supaya
 * command hilir membaca tanggal yang sudah segar.
 */
class SyncDomainExpiryCommand extends Command
{
    protected $signature = 'providers:sync-domain-expiry
                            {--provider= : Sinkronkan hanya satu provider (berdasarkan id atau nama)}';

    protected $description = 'Segarkan tanggal kedaluwarsa domain dari semua provider aktif (idempotent, tanpa duplikasi)';

    public function handle(ProviderServiceImporter $importer): int
    {
        $only = $this->option('provider');

        $query = DomainProvider::query()->active()->orderBy('name');

        if (is_string($only) && trim($only) !== '') {
            $needle = trim($only);
            $query->where(fn ($w) => $w->where('id', $needle)->orWhere('name', $needle));
        }

        $providers = $query->get();

        if ($providers->isEmpty()) {
            $this->info('Tidak ada provider domain aktif — tidak ada yang disinkronkan.');
            Log::info('providers:sync-domain-expiry selesai: tidak ada provider aktif.');

            return self::SUCCESS;
        }

        $totals = ['created' => 0, 'updated' => 0, 'skipped' => 0];
        $failed = 0;

        foreach ($providers as $provider) {
            try {
                $result = $importer->import($provider);

                $provider->forceFill(['last_used_at' => now()])->save();

                $totals['created'] += $result['created'];
                $totals['updated'] += $result['updated'];
                $totals['skipped'] += $result['skipped'];

                $this->info(sprintf(
                    '[%s] %d disegarkan, %d baru, %d dilewati.',
                    $provider->name,
                    $result['updated'],
                    $result['created'],
                    $result['skipped'],
                ));
            } catch (\Throwable $e) {
                $failed++;

                // Jangan bocorkan kredensial ke log — hanya pesan exception.
                Log::warning('Sync tanggal kedaluwarsa domain gagal untuk satu provider', [
                    'provider_id' => $provider->id,
                    'provider' => $provider->name,
                    'driver' => $provider->driver,
                    'exception' => $e->getMessage(),
                ]);

                $this->warn("[{$provider->name}] gagal: {$e->getMessage()}");
            }
        }

        $summary = sprintf(
            'providers:sync-domain-expiry selesai: %d provider, %d sukses, %d gagal — %d end_date disegarkan, %d layanan baru, %d domain belum dipetakan (dilewati).',
            $providers->count(),
            $providers->count() - $failed,
            $failed,
            $totals['updated'],
            $totals['created'],
            $totals['skipped'],
        );

        if ($failed > 0) {
            Log::warning($summary);
        } else {
            Log::info($summary);
        }

        $this->info($summary);

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }
}
