<?php

namespace App\Domains\Security\Http\Controllers;

use App\Domains\Clients\Models\Client;
use App\Domains\Security\Http\Requests\SecurityReportRequest;
use App\Domains\Security\Jobs\SendSecurityReportEmailJob;
use App\Domains\Security\Models\SecurityReport;
use App\Domains\Security\Services\SecurityReportDelivery;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

class SecurityReportController extends Controller
{
    public function index(Request $request): View
    {
        $reports = SecurityReport::with('client')
            ->when($request->filled('client_id'), fn ($q) => $q->where('client_id', $request->integer('client_id')))
            ->orderByDesc('period')
            ->orderByDesc('id')
            ->paginate(25)
            ->withQueryString();

        return view('security.reports.index', [
            'reports' => $reports,
            'clients' => Client::orderBy('name')->get(['id', 'name']),
        ]);
    }

    public function create(Request $request): View
    {
        return view('security.reports.create', [
            'clients' => Client::orderBy('name')->get(['id', 'name']),
            'defaultPeriod' => now()->subMonth()->format('Y-m'),
            'defaultClientId' => $request->integer('client_id') ?: null,
        ]);
    }

    public function store(SecurityReportRequest $request): RedirectResponse
    {
        $data = $request->validated();
        $disk = config('crm.security.report_disk', 'local');

        $path = $request->file('file')->store("security-reports/{$data['client_id']}", $disk);

        $report = SecurityReport::create([
            'client_id' => $data['client_id'],
            'period' => $data['period'],
            'file_path' => $path,
        ]);

        return redirect()->route('security.reports.index')
            ->with('success', "Laporan periode {$report->period} untuk {$report->client->name} diunggah.");
    }

    public function download(SecurityReport $report): StreamedResponse
    {
        abort_unless($report->hasFile(), 404);

        $disk = config('crm.security.report_disk', 'local');

        abort_unless(Storage::disk($disk)->exists($report->file_path), 404);

        return Storage::disk($disk)->download($report->file_path, $report->downloadName());
    }

    /** Draf -> terkirim: laporan tampil di halaman publik klien. */
    public function send(SecurityReport $report): RedirectResponse
    {
        $report->markSent();

        return back()->with('success', "Laporan periode {$report->period} ditandai terkirim.");
    }

    /**
     * Kirim PDF laporan ke email kontak klien + CC info@mcimedia.net (F4-3),
     * lalu tandai laporan terkirim setelah pengiriman berhasil.
     */
    public function sendToClient(SecurityReport $report): RedirectResponse
    {
        $report->loadMissing('client.contacts');

        if (! $report->hasFile()) {
            return back()->with('error', 'Laporan belum punya berkas PDF sehingga tidak dapat dikirim ke klien.');
        }

        $disk = config('crm.security.report_disk', 'local');

        if (! Storage::disk($disk)->exists($report->file_path)) {
            return back()->with('error', 'Berkas PDF laporan tidak ditemukan di penyimpanan.');
        }

        $to = SecurityReportDelivery::recipients($report);

        if ($to === []) {
            return back()->with('error', 'Klien belum punya alamat email kontak sehingga laporan tidak dapat dikirim.');
        }

        SendSecurityReportEmailJob::dispatch($report);

        $cc = SecurityReportDelivery::ccEmail();
        $ccNote = $cc !== null ? " (CC {$cc})" : '';

        return back()->with('success', "Pengiriman laporan periode {$report->period} ke ".implode(', ', $to)."{$ccNote} sedang diantre.");
    }

    public function destroy(SecurityReport $report): RedirectResponse
    {
        $disk = config('crm.security.report_disk', 'local');

        if ($report->hasFile() && Storage::disk($disk)->exists($report->file_path)) {
            Storage::disk($disk)->delete($report->file_path);
        }

        $period = $report->period;
        $report->delete();

        return redirect()->route('security.reports.index')
            ->with('success', "Laporan periode {$period} dihapus.");
    }
}
