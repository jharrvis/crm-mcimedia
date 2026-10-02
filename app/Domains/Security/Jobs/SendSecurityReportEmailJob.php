<?php

namespace App\Domains\Security\Jobs;

use App\Domains\Security\Mail\SecurityReportMailable;
use App\Domains\Security\Models\SecurityReport;
use App\Domains\Security\Services\SecurityReportDelivery;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;

/**
 * Kirim laporan keamanan ke email kontak klien + CC tetap sebagai queued job
 * (koneksi database), dengan lampiran PDF dari disk privat.
 *
 * Job dilewati (skip) + peringatan log bila berkas hilang atau tidak ada
 * penerima — tanpa exception dan tanpa mengubah status laporan, sehingga
 * laporan tidak pernah ditandai terkirim tanpa benar-benar dikirim.
 */
class SendSecurityReportEmailJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 3;

    public function __construct(public SecurityReport $report)
    {
        $this->onConnection('database');
    }

    public function handle(): void
    {
        $report = $this->report->fresh(['client.contacts']);

        if ($report === null) {
            Log::warning('Pengiriman email laporan keamanan dilewati: laporan tidak ditemukan.');

            return;
        }

        if (! $report->hasFile()) {
            Log::warning('Pengiriman email laporan keamanan dilewati: laporan tanpa berkas PDF.', [
                'report' => $report->id,
                'period' => $report->period,
            ]);

            return;
        }

        $disk = config('crm.security.report_disk', 'local');

        if (! Storage::disk($disk)->exists($report->file_path)) {
            Log::warning('Pengiriman email laporan keamanan dilewati: berkas PDF tidak ditemukan di penyimpanan.', [
                'report' => $report->id,
                'disk' => $disk,
                'file_path' => $report->file_path,
            ]);

            return;
        }

        $to = SecurityReportDelivery::recipients($report);

        if ($to === []) {
            Log::warning('Pengiriman email laporan keamanan dilewati: klien tidak punya alamat email.', [
                'report' => $report->id,
            ]);

            return;
        }

        $cc = SecurityReportDelivery::ccEmail();

        $mailer = Mail::to($to);

        if ($cc !== null) {
            $mailer->cc($cc);
        }

        $mailer->send(new SecurityReportMailable($report));

        SecurityReportDelivery::markDelivered($report, $to, $cc);
    }
}
