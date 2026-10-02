<?php

namespace App\Domains\Hestia\Http\Controllers;

use App\Domains\Clients\Models\Client;
use App\Domains\Hestia\Http\Requests\HestiaMapRequest;
use App\Domains\Hestia\Models\HestiaAccount;
use App\Domains\Hestia\Models\HestiaServer;
use App\Domains\Hestia\Models\HestiaSyncLog;
use App\Domains\Hestia\Services\HestiaClient;
use App\Domains\Hestia\Services\HestiaSyncOrchestrator;
use App\Domains\Hestia\Services\HestiaSyncService;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

class HestiaController extends Controller
{
    public function index(Request $request, HestiaClient $client)
    {
        $serverFilter = $this->resolveServerFilter($request);

        $accountsQuery = HestiaAccount::with(['client', 'service', 'server'])
            ->orderBy('domain');

        $this->applyServerFilter($accountsQuery, $serverFilter);

        return view('hestia.index', [
            'accounts' => $accountsQuery->paginate(25)->withQueryString(),
            'unmapped' => $this->unmappedQuery($serverFilter)->orderBy('domain')->get(),
            'logs' => HestiaSyncLog::with('server')->latest()->limit(15)->get(),
            'clients' => Client::orderBy('name')->get(['id', 'name']),
            'servers' => HestiaServer::orderBy('name')->get(),
            'serverFilter' => $serverFilter,
            'configured' => (bool) config('crm.hestia.enabled', false) && $client->isConfigured(),
            'hasManagedServers' => HestiaServer::query()->exists(),
            'envEnabled' => (bool) config('crm.hestia.enabled', false),
        ]);
    }

    /**
     * Sinkronkan semua server aktif (F4-12). Server yang gagal dilaporkan
     * tersendiri tanpa menghentikan server lain.
     */
    public function sync(HestiaSyncOrchestrator $orchestrator)
    {
        $logs = $orchestrator->syncAll();
        $summary = $orchestrator->summarize($logs);

        if ($summary['failed'] > 0) {
            $failedNames = $logs
                ->filter(fn (HestiaSyncLog $log) => ! $log->isSuccess())
                ->map(fn (HestiaSyncLog $log) => $log->sourceLabel().': '.($log->message ?? 'tidak diketahui'))
                ->implode(' | ');

            return redirect()->route('hestia.index')
                ->with('error', "Sinkronisasi selesai sebagian — {$summary['failed']} dari {$summary['total']} sumber gagal. {$failedNames}");
        }

        return redirect()->route('hestia.index')
            ->with('success', "Sinkronisasi selesai: {$summary['total']} sumber, {$summary['pulled']} akun ditarik, {$summary['created']} baru, {$summary['updated']} diperbarui, {$summary['deactivated']} dinonaktifkan.");
    }

    public function map(HestiaMapRequest $request, HestiaAccount $account, HestiaSyncService $sync)
    {
        $service = $sync->assignClient($account, $request->integer('client_id'));

        return redirect()->route('hestia.index')
            ->with('success', "Akun {$account->domain} dipetakan ke layanan #{$service->id}.");
    }

    public function ignore(HestiaAccount $account, HestiaSyncService $sync)
    {
        if ($account->service_id !== null) {
            return redirect()->route('hestia.index')
                ->with('error', "Akun {$account->domain} sudah terhubung ke sebuah layanan dan tidak bisa diabaikan.");
        }

        $sync->ignoreAccount($account);

        return redirect()->route('hestia.index')
            ->with('success', "Akun {$account->domain} diabaikan.");
    }

    /**
     * Normalisasi parameter filter server dari query string.
     *
     * Nilai yang mungkin: `all` (default), `env` (akun tanpa server / path F3-1),
     * atau `srv:<id>` untuk satu server tertentu. Nilai tak dikenal diabaikan
     * agar query string rusak tidak membuat halaman error.
     */
    private function resolveServerFilter(Request $request): string
    {
        $raw = trim((string) $request->query('server', 'all'));

        if ($raw === '' || $raw === 'all') {
            return 'all';
        }

        if ($raw === 'env') {
            return 'env';
        }

        if (preg_match('/^srv:(\d+)$/', $raw, $matches) === 1) {
            $id = (int) $matches[1];

            return HestiaServer::query()->whereKey($id)->exists() ? $raw : 'all';
        }

        return 'all';
    }

    /** Terapkan filter server pada query akun. */
    private function applyServerFilter($query, string $filter): void
    {
        if ($filter === 'env') {
            $query->whereNull('hestia_server_id');

            return;
        }

        if (preg_match('/^srv:(\d+)$/', $filter, $matches) === 1) {
            $query->where('hestia_server_id', (int) $matches[1]);
        }
    }

    /** Query akun belum dipetakan dengan filter server yang sama. */
    private function unmappedQuery(string $filter)
    {
        $query = HestiaAccount::unmapped()->with('server');

        $this->applyServerFilter($query, $filter);

        return $query;
    }
}
