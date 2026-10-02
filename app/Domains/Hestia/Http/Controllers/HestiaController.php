<?php

namespace App\Domains\Hestia\Http\Controllers;

use App\Domains\Clients\Models\Client;
use App\Domains\Hestia\Http\Requests\HestiaMapRequest;
use App\Domains\Hestia\Models\HestiaAccount;
use App\Domains\Hestia\Models\HestiaSyncLog;
use App\Domains\Hestia\Services\HestiaClient;
use App\Domains\Hestia\Services\HestiaSyncService;
use App\Http\Controllers\Controller;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;

class HestiaController extends Controller
{
    /** Nilai filter yang sah — input lain diabaikan, bukan diteruskan ke query. */
    private const STATUS_FILTERS = [
        '' => 'Semua status',
        'active' => 'Aktif',
        'inactive' => 'Nonaktif',
        'suspended' => 'Suspend',
    ];

    private const QUOTA_FILTERS = [
        '' => 'Semua kuota',
        'near_limit' => 'Hampir penuh (≥ 80%)',
        'full' => 'Penuh (≥ 100%)',
        'unlimited' => 'Tanpa batas',
        'unknown' => 'Belum ada data',
    ];

    public function index(Request $request, HestiaClient $client)
    {
        return view('hestia.index', [
            'accounts' => $this->accountsQuery($request)
                ->with(['client', 'service'])
                ->orderBy('domain')
                ->paginate(25)
                ->withQueryString(),
            'unmapped' => HestiaAccount::unmapped()->orderBy('domain')->get(),
            'logs' => HestiaSyncLog::latest()->limit(10)->get(),
            'clients' => Client::orderBy('name')->get(['id', 'name']),
            'configured' => (bool) config('crm.hestia.enabled', false) && $client->isConfigured(),
            'plans' => HestiaAccount::query()
                ->whereNotNull('plan')
                ->distinct()
                ->orderBy('plan')
                ->pluck('plan'),
            'summary' => $this->summary(),
            'statusFilters' => self::STATUS_FILTERS,
            'quotaFilters' => self::QUOTA_FILTERS,
        ]);
    }

    /**
     * Query akun tersinkron + filter paket/kuota/status (F4-13).
     *
     * Setiap nilai filter dicocokkan ke daftar putih; input tak dikenal
     * diperlakukan sebagai "semua" sehingga tidak pernah masuk ke klausa SQL.
     */
    private function accountsQuery(Request $request): Builder
    {
        $query = HestiaAccount::query();

        $search = trim((string) $request->query('q', ''));
        if ($search !== '') {
            // `%` dan `_` di-escape agar dicari apa adanya, lalu dicocokkan dengan
            // klausa ESCAPE eksplisit. Escape default backslash hanya berlaku di
            // MySQL; SQLite menolaknya sehingga tanpa ESCAPE pencarian domain
            // ber-underscore (mis. my_domain.com) selalu gagal di dev/test.
            //
            // Pola tetap di-bind sebagai PARAMETER (tidak pernah disisipkan ke
            // SQL); yang ditulis literal hanyalah karakter escape, yaitu
            // konstanta milik kode — bukan input pengguna. Bentuk literal
            // dipakai karena dukungan placeholder pada klausa ESCAPE berbeda
            // antar versi MySQL, sedangkan literal ini valid di keduanya.
            $pattern = '%'.addcslashes($search, '%_\\').'%';

            $query->where(function (Builder $inner) use ($pattern): void {
                $inner->whereRaw("domain LIKE ? ESCAPE '\\'", [$pattern])
                    ->orWhereRaw("hestia_user LIKE ? ESCAPE '\\'", [$pattern]);
            });
        }

        $plan = (string) $request->query('plan', '');
        if ($plan !== '') {
            $query->where('plan', $plan);
        }

        $status = (string) $request->query('status', '');
        if ($status === 'suspended') {
            // Suspensi bisa datang dari domain ATAU dari akun Hestia pemiliknya.
            $query->suspended();
        } elseif (in_array($status, ['active', 'inactive'], true)) {
            // "Aktif/Nonaktif" di UI berarti "tidak disuspend DAN statusnya …",
            // supaya hasil filter tidak pernah memunculkan bar berlabel "Suspend".
            $query->notSuspended()->where('status', $status);
        }

        return $this->applyQuotaFilter($query, (string) $request->query('quota', ''));
    }

    /** Filter kuota memakai perbandingan kolom langsung (portabel SQLite/MySQL). */
    private function applyQuotaFilter(Builder $query, string $quota): Builder
    {
        return match ($quota) {
            'near_limit' => $query
                ->whereNotNull('disk_quota')->where('disk_quota', '>', 0)
                ->whereNotNull('disk_used')
                ->whereRaw('disk_used >= disk_quota * 0.8'),
            'full' => $query
                ->whereNotNull('disk_quota')->where('disk_quota', '>', 0)
                ->whereNotNull('disk_used')
                ->whereRaw('disk_used >= disk_quota'),
            // Kuota 0 = tanpa batas (konvensi paket Hestia).
            'unlimited' => $query->where('disk_quota', 0),
            'unknown' => $query->whereNull('disk_quota'),
            default => $query,
        };
    }

    /**
     * Ringkasan untuk kartu di atas tabel (F4-13).
     *
     * Dihitung di database (bukan di Blade) supaya tidak memuat seluruh tabel
     * ke memori hanya untuk menghitung lima angka.
     *
     * @return list<array{label: string, value: string, alert: bool}>
     */
    private function summary(): array
    {
        $total = HestiaAccount::query()->count();
        // Setara dengan filter "Aktif" di UI: bukan sekadar status=active, tapi
        // juga tidak disuspend — agar kartu tidak menghitung akun yang faktanya mati.
        $active = HestiaAccount::active()->notSuspended()->count();
        $suspended = HestiaAccount::suspended()->count();
        $nearLimit = $this->applyQuotaFilter(HestiaAccount::query(), 'near_limit')->count();

        $diskUsedMb = (int) HestiaAccount::query()->whereNotNull('disk_used')->sum('disk_used');

        return [
            ['label' => 'Total akun', 'value' => (string) $total, 'alert' => false],
            ['label' => 'Aktif', 'value' => (string) $active, 'alert' => false],
            ['label' => 'Suspend', 'value' => (string) $suspended, 'alert' => $suspended > 0],
            ['label' => 'Hampir penuh', 'value' => (string) $nearLimit, 'alert' => $nearLimit > 0],
            ['label' => 'Total disk terpakai', 'value' => self::formatMegabytes($diskUsedMb), 'alert' => false],
        ];
    }

    /** MB -> "512 MB" / "1,5 GB" (satuan sama dengan yang dipakai view). */
    private static function formatMegabytes(int $megabytes): string
    {
        if ($megabytes >= 1024) {
            return number_format($megabytes / 1024, 1, ',', '.').' GB';
        }

        return $megabytes.' MB';
    }

    public function sync(HestiaSyncService $sync)
    {
        $log = $sync->sync();

        if (! $log->isSuccess()) {
            return redirect()->route('hestia.index')
                ->with('error', 'Sinkronisasi gagal: '.($log->message ?? 'tidak diketahui'));
        }

        return redirect()->route('hestia.index')
            ->with('success', "Sinkronisasi selesai: {$log->pulled} akun ditarik, {$log->created} baru, {$log->updated} diperbarui, {$log->deactivated} dinonaktifkan.");
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
}
