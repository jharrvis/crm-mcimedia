<?php

namespace App\Domains\Services\Http\Controllers;

use App\Domains\Clients\Models\Client;
use App\Domains\Providers\Models\DomainProvider;
use App\Domains\Services\Enums\ServiceType;
use App\Domains\Services\Models\Service;
use App\Http\Controllers\Controller;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;

/**
 * Daftar seluruh domain (t_ee922ded).
 *
 * Satu tabel khusus layanan berjenis `domain` — halaman `services.index`
 * mencampur semua jenis layanan sehingga sulit dipakai untuk memantau
 * kedaluwarsa domain dan asal registrar-nya.
 *
 * Modul permission: `services` — sumber datanya tabel `services`, jadi user
 * yang boleh melihat layanan otomatis boleh melihat daftar domain ini; tidak
 * ada modul/role baru.
 */
class DomainController extends Controller
{
    /** Kolom yang boleh diurutkan (whitelist) — input lain diabaikan. */
    private const SORTABLE = ['name', 'expired', 'client'];

    public function index(Request $request)
    {
        $sort = (string) $request->query('sort', 'expired');
        $sort = in_array($sort, self::SORTABLE, true) ? $sort : 'expired';
        $dir = strtolower((string) $request->query('dir', 'asc')) === 'desc' ? 'desc' : 'asc';

        $domains = $this->query($request, $sort, $dir)
            ->paginate(25)
            ->withQueryString();

        return view('domains.index', [
            'domains' => $domains,
            'clients' => Client::orderBy('name')->get(['id', 'name']),
            'providers' => DomainProvider::orderBy('name')->get(['id', 'name']),
            'sort' => $sort,
            'dir' => $dir,
        ]);
    }

    private function query(Request $request, string $sort, string $dir): Builder
    {
        $query = Service::query()
            ->where('services.type', ServiceType::Domain)
            ->with(['client', 'domainProvider', 'parent']);

        $search = trim((string) $request->query('q', ''));
        if ($search !== '') {
            // `%`/`_` di-escape supaya dicari apa adanya (domain sering
            // mengandung underscore) dan dicocokkan dengan klausa ESCAPE
            // eksplisit — escape default backslash hanya berlaku di MySQL,
            // SQLite menolaknya. Pola tetap di-bind sebagai parameter.
            $pattern = '%'.addcslashes($search, '%_\\').'%';

            $query->where(function (Builder $inner) use ($pattern): void {
                $inner->whereRaw('services.name LIKE ? ESCAPE \'\\\'', [$pattern])
                    ->orWhereRaw('services.reference LIKE ? ESCAPE \'\\\'', [$pattern]);
            });
        }

        $provider = (string) $request->query('provider', '');
        if ($provider === 'none') {
            $query->whereNull('services.domain_provider_id');
        } elseif (ctype_digit($provider) && (int) $provider > 0) {
            $query->where('services.domain_provider_id', (int) $provider);
        }

        $clientId = $request->integer('client_id');
        if ($clientId > 0) {
            $query->where('services.client_id', $clientId);
        }

        // Sort klien lewat subquery nama klien (bukan join) agar jumlah baris
        // hasil tidak berubah dan filter `services.*` tetap unambiguous.
        if ($sort === 'client') {
            $query->orderBy(
                Client::query()->select('name')->whereColumn('clients.id', 'services.client_id'),
                $dir,
            );
        } else {
            $query->orderBy('services.'.($sort === 'expired' ? 'end_date' : 'name'), $dir);
        }

        // Tie-break stabil supaya paginasi tidak mengacak baris berurutan sama.
        return $query->orderBy('services.id');
    }
}
