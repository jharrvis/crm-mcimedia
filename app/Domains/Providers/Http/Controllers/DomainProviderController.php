<?php

namespace App\Domains\Providers\Http\Controllers;

use App\Domains\Clients\Models\Client;
use App\Domains\Providers\DomainProviderRegistry;
use App\Domains\Providers\Drivers\NameSiloDomainProviderDriver;
use App\Domains\Providers\Http\Requests\DomainAutoRenewRequest;
use App\Domains\Providers\Http\Requests\DomainProviderRequest;
use App\Domains\Providers\Http\Requests\ImportProviderServicesRequest;
use App\Domains\Providers\Models\DomainProvider;
use App\Domains\Providers\Services\ProviderServiceImporter;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * UI registry penyedia domain/hosting (F4-5).
 *
 * Tambah/ubah/hapus/aktifkan provider + lihat daftar domain dari driver.
 * Seluruh aksi di balik auth (lihat routes/web.php).
 */
class DomainProviderController extends Controller
{
    public function __construct(
        private readonly DomainProviderRegistry $registry,
        private readonly ProviderServiceImporter $importer,
    ) {}

    public function index(Request $request)
    {
        $status = $request->string('status', 'all')->toString();

        $providers = DomainProvider::query()
            ->when($request->filled('q'), function ($query) use ($request) {
                $q = (string) $request->string('q');
                $query->where(fn ($w) => $w->where('name', 'like', "%{$q}%")
                    ->orWhere('driver', 'like', "%{$q}%"));
            })
            ->when(in_array($status, ['active', 'inactive'], true), fn ($query) => $query->where('is_active', $status === 'active'))
            ->orderBy('name')
            ->paginate(20)
            ->withQueryString();

        return view('domain-providers.index', [
            'providers' => $providers,
            'statusFilter' => $status,
            'drivers' => $this->registry->options(),
        ]);
    }

    public function create(Request $request)
    {
        $driverKey = old('driver', (string) $request->string('driver')) ?: (array_key_first($this->registry->options()) ?: 'manual');

        return view('domain-providers.create', [
            'drivers' => $this->registry->options(),
            'driverKey' => $driverKey,
            'fields' => $this->registry->has($driverKey) ? $this->registry->credentialFields($driverKey) : [],
            'values' => [],
        ]);
    }

    public function store(DomainProviderRequest $request)
    {
        $data = $request->validated();

        $provider = DomainProvider::create([
            'name' => $data['name'],
            'driver' => $data['driver'],
            'credentials' => $this->credentialPayload($request, $data['driver'], null),
            'notes' => $data['notes'] ?? null,
            'is_active' => $request->boolean('is_active', true),
        ]);

        return redirect()->route('domain-providers.index')
            ->with('success', "Provider \"{$provider->name}\" berhasil ditambahkan.");
    }

    public function edit(Request $request, DomainProvider $domainProvider)
    {
        $driverKey = old('driver', (string) $request->string('driver')) ?: $domainProvider->driver;
        $sameDriver = $driverKey === $domainProvider->driver;

        return view('domain-providers.edit', [
            'provider' => $domainProvider,
            'drivers' => $this->registry->options(),
            'driverKey' => $driverKey,
            'fields' => $this->registry->has($driverKey)
                ? $this->registry->credentialFields($driverKey)
                : [],
            'values' => $sameDriver ? $domainProvider->safeCredentials() : [],
        ]);
    }

    public function update(DomainProviderRequest $request, DomainProvider $domainProvider)
    {
        $data = $request->validated();

        $domainProvider->update([
            'name' => $data['name'],
            'driver' => $data['driver'],
            'credentials' => $this->credentialPayload($request, $data['driver'], $domainProvider),
            'notes' => $data['notes'] ?? null,
            'is_active' => $request->boolean('is_active', $domainProvider->is_active),
        ]);

        return redirect()->route('domain-providers.index')
            ->with('success', "Provider \"{$domainProvider->name}\" berhasil diperbarui.");
    }

    public function destroy(DomainProvider $domainProvider)
    {
        $name = $domainProvider->name;
        $domainProvider->delete();

        return redirect()->route('domain-providers.index')
            ->with('success', "Provider \"{$name}\" dihapus.");
    }

    /** Aktif/nonaktifkan provider. */
    public function toggle(DomainProvider $domainProvider)
    {
        $domainProvider->update(['is_active' => ! $domainProvider->is_active]);

        return back()->with('success', $domainProvider->is_active
            ? "Provider \"{$domainProvider->name}\" diaktifkan."
            : "Provider \"{$domainProvider->name}\" dinonaktifkan.");
    }

    /**
     * Tampilkan domain dari provider lewat driver (listDomains + getExpiry).
     * Kegagalan driver (kredensial salah/jaringan) ditampilkan sebagai pesan,
     * bukan error 500.
     */
    public function domains(DomainProvider $domainProvider)
    {
        $items = [];
        $error = null;

        try {
            $driver = $this->registry->make($domainProvider);

            foreach ($driver->listDomains() as $info) {
                $items[] = [
                    'info' => $info,
                    'expiry' => $info->expiresAt ?? $driver->getExpiry($info->domain),
                    // Auto-renew hanya ikut terbawa bila driver melakukan
                    // enrichment (satu request/domain); driver lain selalu null.
                    // Membaca dari `raw` menghindari request tambahan per baris.
                    'auto_renew' => is_bool($info->raw['auto_renew'] ?? null)
                        ? $info->raw['auto_renew']
                        : null,
                ];
            }

            $domainProvider->forceFill(['last_used_at' => now()])->save();
        } catch (\Throwable $e) {
            // Jangan bocorkan detail kredensial ke pesan; detail lengkap ke log.
            Log::warning('Gagal menarik domain dari provider', [
                'provider_id' => $domainProvider->id,
                'driver' => $domainProvider->driver,
                'exception' => $e->getMessage(),
            ]);
            $error = $e->getMessage();
        }

        return view('domain-providers.domains', [
            'provider' => $domainProvider,
            'items' => $items,
            'error' => $error,
            'supportsAutoRenew' => $domainProvider->driver === NameSiloDomainProviderDriver::key(),
            'clients' => Client::query()->orderBy('name')->get(),
        ]);
    }

    /**
     * Aktif/nonaktifkan auto-renew satu domain (F4-7).
     *
     * Hanya berlaku untuk driver yang mendukungnya (NameSilo). Kegagalan API
     * dilaporkan sebagai flash error — pesan driver aman (tanpa API key).
     */
    public function autoRenew(DomainAutoRenewRequest $request, DomainProvider $domainProvider)
    {
        // Redirect eksplisit ke daftar domain: `back()` bergantung pada referer
        // yang tidak selalu ada (mis. dipanggil langsung), dan justru ke /
        // yang membingungkan admin.
        $redirect = redirect()->route('domain-providers.domains', $domainProvider);

        if ($domainProvider->driver !== NameSiloDomainProviderDriver::key()) {
            return $redirect->with('error', 'Driver ini tidak mendukung pengaturan auto-renew.');
        }

        $domain = (string) $request->string('domain')->toString();
        $enable = $request->boolean('enable');

        try {
            $this->registry->make($domainProvider)->setAutoRenew($domain, $enable);
        } catch (\Throwable $e) {
            Log::warning('Gagal mengubah auto-renew domain', [
                'provider_id' => $domainProvider->id,
                'domain' => $domain,
                'exception' => $e->getMessage(),
            ]);

            return $redirect->with('error', $e->getMessage());
        }

        return $redirect->with('success', sprintf(
            'Auto-renew %s untuk domain %s.',
            $enable ? 'diaktifkan' : 'dinonaktifkan',
            $domain,
        ));
    }

    /**
     * Impor domain dari provider menjadi data layanan milik seorang klien (F4-7).
     *
     * Idempotent: domain yang sudah menjadi layanan hanya tanggal kedaluwarsanya
     * yang disegarkan (lihat ProviderServiceImporter).
     */
    public function importServices(ImportProviderServicesRequest $request, DomainProvider $domainProvider)
    {
        $redirect = redirect()->route('domain-providers.domains', $domainProvider);

        try {
            $result = $this->importer->import($domainProvider, (int) $request->integer('client_id'));
        } catch (\Throwable $e) {
            Log::warning('Gagal mengimpor domain provider ke layanan', [
                'provider_id' => $domainProvider->id,
                'exception' => $e->getMessage(),
            ]);

            return $redirect->with('error', 'Gagal mengimpor domain: '.$e->getMessage());
        }

        return $redirect->with('success', sprintf(
            'Impor selesai: %d layanan baru, %d diperbarui.',
            $result['created'],
            $result['updated'],
        ));
    }

    /**
     * Susun array kredensial dari input, hanya untuk field yang dideklarasikan
     * driver. Field rahasia yang dikosongkan saat edit mempertahankan nilai lama.
     */
    private function credentialPayload(DomainProviderRequest $request, string $driverKey, ?DomainProvider $existing): array
    {
        $fields = $this->registry->credentialFields($driverKey);
        $old = $existing !== null && is_array($existing->credentials) ? $existing->credentials : [];
        $payload = [];

        foreach ($fields as $field => $definition) {
            $type = (string) ($definition['type'] ?? 'text');
            $secret = (bool) ($definition['secret'] ?? false);
            $default = $definition['default'] ?? null;

            if ($type === 'checkbox') {
                $payload[$field] = $request->boolean("credentials.{$field}", (bool) ($default ?? false));

                continue;
            }

            $value = $request->input("credentials.{$field}");

            if (is_string($value)) {
                $value = trim($value);
            }

            if ($value === null || $value === '') {
                if ($secret && array_key_exists($field, $old)) {
                    $payload[$field] = $old[$field];   // pertahankan rahasia lama
                } elseif ($default !== null) {
                    $payload[$field] = $default;
                }

                continue;
            }

            $payload[$field] = $type === 'number' ? (int) $value : $value;
        }

        return $payload;
    }
}
