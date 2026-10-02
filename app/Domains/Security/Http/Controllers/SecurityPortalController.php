<?php

namespace App\Domains\Security\Http\Controllers;

use App\Domains\Clients\Models\Client;
use App\Domains\Security\Enums\ReportStatus;
use App\Domains\Security\Jobs\SendPortalSecurityReportEmailJob;
use App\Domains\Security\Models\SecurityReport;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;

/**
 * Halaman laporan keamanan publik per klien (magic link F3-3) — tanpa login,
 * diakses lewat clients.security_portal_token 64 karakter. Hanya laporan
 * berstatus `sent` yang tampil; token kosong/salah/dicabut → 404.
 *
 * Sejak F4-4 klien TIDAK lagi mengunduh PDF langsung: tombol "Kirim via Email"
 * mengirim PDF ke email terdaftar klien. Dengan begitu dokumen rahasia tetap
 * berada di kotak surat klien dan tautan portal tidak bisa dipakai untuk
 * mengunduh/menyebarkan berkas ke pihak lain.
 *
 * Tidak pernah membocorkan data klien lain: setiap request hanya memuat data
 * klien yang cocok dengan token.
 */
class SecurityPortalController extends Controller
{
    /** Lama jeda minimum (menit) sebelum laporan yang sama boleh dikirim ulang. */
    private const RESEND_COOLDOWN_MINUTES = 5;

    /** Cari klien berdasarkan token publik; token kosong/salah/dicabut -> 404. */
    private function findClient(string $token): Client
    {
        $client = Client::where('security_portal_token', $token)->first();

        abort_if($client === null, 404);

        return $client;
    }

    /** GET /security/report/{token} */
    public function show(string $token): View
    {
        $client = $this->findClient($token);

        $reports = $client->securityReports()
            ->sent()
            ->orderByDesc('period')
            ->get();

        return view('security.portal', [
            'client' => $client,
            'reports' => $reports,
            'business' => config('crm.business'),
        ]);
    }

    /**
     * POST /security/report/{token}/reports/{report}/email
     *
     * Kirim PDF laporan ke email terdaftar klien (bukan unduh langsung).
     * Laporan milik klien lain / bukan status terkirim → 404 agar keberadaan
     * laporan tidak pernah terbocor lewat token yang salah.
     */
    public function email(string $token, SecurityReport $report): RedirectResponse
    {
        $client = $this->findClient($token);

        // Laporan harus milik klien ini dan sudah terkirim.
        abort_if($report->client_id !== $client->id, 404);
        abort_if($report->status !== ReportStatus::Sent, 404);

        if (! $report->hasFile()) {
            return back()->with('error', 'Laporan belum punya berkas PDF sehingga tidak dapat dikirim.');
        }

        $disk = config('crm.security.report_disk', 'local');

        if (! Storage::disk($disk)->exists($report->file_path)) {
            return back()->with('error', 'Berkas PDF laporan tidak ditemukan. Silakan hubungi kami.');
        }

        $email = trim((string) $client->email);

        if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            return back()->with('error', 'Email klien belum terdaftar sehingga laporan tidak dapat dikirim.');
        }

        // Cegah spam/penyalahgunaan token: satu laporan hanya boleh dikirim
        // ulang setiap RESEND_COOLDOWN_MINUTES menit.
        $throttleKey = 'security-portal-report-email:'.$report->getKey();

        if (! Cache::add($throttleKey, true, now()->addMinutes(self::RESEND_COOLDOWN_MINUTES))) {
            return back()->with('error', 'Laporan ini baru saja dikirim. Silakan coba lagi beberapa menit lagi.');
        }

        SendPortalSecurityReportEmailJob::dispatch($report);

        return back()->with('success', "Laporan periode {$report->period} sedang dikirim ke email terdaftar Anda.");
    }
}
