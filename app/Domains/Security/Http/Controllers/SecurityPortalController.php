<?php

namespace App\Domains\Security\Http\Controllers;

use App\Domains\Clients\Models\Client;
use App\Domains\Security\Enums\ReportStatus;
use App\Domains\Security\Models\SecurityReport;
use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Halaman laporan keamanan publik per klien (magic link F3-3) — tanpa login,
 * diakses lewat clients.security_portal_token 64 karakter. Hanya laporan
 * berstatus `sent` yang tampil; token kosong/salah/dicabut → 404.
 *
 * Tidak pernah membocorkan data klien lain: setiap request hanya memuat data
 * klien yang cocok dengan token.
 */
class SecurityPortalController extends Controller
{
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

    /** GET /security/report/{token}/reports/{report}/download */
    public function download(string $token, SecurityReport $report): StreamedResponse
    {
        $client = $this->findClient($token);

        // Laporan harus milik klien ini dan sudah terkirim.
        abort_if($report->client_id !== $client->id, 404);
        abort_if($report->status !== ReportStatus::Sent, 404);
        abort_unless($report->hasFile(), 404);

        $disk = config('crm.security.report_disk', 'local');

        abort_unless(Storage::disk($disk)->exists($report->file_path), 404);

        return Storage::disk($disk)->download($report->file_path, $report->downloadName());
    }
}
