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
 * Kirim PDF laporan keamanan ke EMAIL TERDAFTAR KLIEN saja (F4-4) sebagai
 * queued job (koneksi database), dipicu dari tombol "Kirim via Email" pada
 * portal publik klien.
 *
 * Berbeda dengan SendSecurityReportEmailJob (F4-3) yang mengirim ke seluruh
 * kontak klien + CC internal, job ini sengaja HANYA mengirim ke email utama
 * klien: laporan bersifat rahasia dan tautan portal tidak boleh menjadi sarana
 * menyebarkan dokumen ke alamat lain.
 *
 * Job dilewati (skip) + peringatan log bila berkas hilang atau email klien
 * tidak valid — tanpa exception dan tanpa menandai laporan terkirim palsu,
 * sehingga status laporan tidak pernah berbohong.
 */
class SendPortalSecurityReportEmailJob implements ShouldQueue
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
        $report = $this->report->fresh(['client']);

        if ($report === null || $report->client === null) {
            Log::warning('Pengiriman email portal laporan keamanan dilewati: laporan/klien tidak ditemukan.', [
                'report' => $this->report->id,
            ]);

            return;
        }

        if (! $report->hasFile()) {
            Log::warning('Pengiriman email portal laporan keamanan dilewati: laporan tanpa berkas PDF.', [
                'report' => $report->id,
                'period' => $report->period,
            ]);

            return;
        }

        $disk = config('crm.security.report_disk', 'local');

        if (! Storage::disk($disk)->exists($report->file_path)) {
            Log::warning('Pengiriman email portal laporan keamanan dilewati: berkas PDF tidak ditemukan di penyimpanan.', [
                'report' => $report->id,
                'disk' => $disk,
                'file_path' => $report->file_path,
            ]);

            return;
        }

        $email = trim((string) $report->client->email);

        if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            Log::warning('Pengiriman email portal laporan keamanan dilewati: email klien tidak valid.', [
                'report' => $report->id,
            ]);

            return;
        }

        Mail::to($email)->send(new SecurityReportMailable($report));

        SecurityReportDelivery::markDelivered($report, [$email], null);
    }
}
