@extends('layouts.app')

@section('title', 'Sinkron Hestia')

@section('content')
@php
    $inputClass = 'rounded-lg border border-slate-300 px-3 py-2 text-sm dark:border-slate-700 dark:bg-slate-800';
    $badge = fn (string $classes, string $text) => '<span class="rounded-full px-2 py-0.5 text-xs font-medium '.$classes.'">'.e($text).'</span>';

    /**
     * Format ukuran disk (MB) untuk tampilan. Sengaja closure lokal, bukan
     * helper global, karena hanya dipakai di halaman ini.
     */
    $mb = function (?int $megabytes): string {
        if ($megabytes === null) {
            return '—';
        }

        if ($megabytes >= 1024) {
            return number_format($megabytes / 1024, 1, ',', '.').' GB';
        }

        return $megabytes.' MB';
    };

    // Warna bar disk: hijau < 80%, kuning 80–99%, merah >= 100% (penuh).
    // Tanpa type-hint: kelas view tidak punya `use`, jadi type-hint unnamed
    // akan resolve ke namespace global dan selalu gagal.
    $diskBar = function ($account): string {
        $percent = $account->diskUsagePercent();

        if ($percent === null) {
            return 'bg-slate-400';
        }

        return match (true) {
            $percent >= 100 => 'bg-red-500',
            $percent >= 80 => 'bg-amber-500',
            default => 'bg-emerald-500',
        };
    };
@endphp

<div class="mb-4 flex flex-wrap items-center gap-2">
    <form method="POST" action="{{ route('hestia.sync') }}">
        @csrf
        <button class="rounded-lg bg-indigo-600 px-4 py-2 text-sm font-semibold text-white hover:bg-indigo-700">
            Sinkronkan semua server
        </button>
    </form>
    <a href="{{ route('hestia.servers.index') }}"
       class="rounded-lg border border-slate-300 px-4 py-2 text-sm font-semibold text-slate-700 hover:bg-slate-50 dark:border-slate-700 dark:text-slate-200 dark:hover:bg-slate-800">
        Kelola server
    </a>

    @if ($servers->isNotEmpty())
        <form method="GET" action="{{ route('hestia.index') }}" class="ml-auto flex items-center gap-2">
            <label for="server" class="text-sm text-slate-500">Server</label>
            <select id="server" name="server" onchange="this.form.submit()"
                    class="rounded-lg border border-slate-300 px-3 py-2 text-sm dark:border-slate-700 dark:bg-slate-800">
                <option value="all" @selected($serverFilter === 'all')>Semua server</option>
                @foreach ($servers as $s)
                    <option value="srv:{{ $s->id }}" @selected($serverFilter === 'srv:'.$s->id)>
                        {{ $s->name }}{{ $s->is_active ? '' : ' (nonaktif)' }}
                    </option>
                @endforeach
                <option value="env" @selected($serverFilter === 'env')>Environment (.env)</option>
            </select>
            <noscript><button class="rounded-lg border border-slate-300 px-3 py-2 text-sm">Terapkan</button></noscript>
        </form>
    @endif
</div>

<p class="mb-4 text-sm text-slate-500">
    Menarik akun hosting/domain dari HestiaCP (read-only, idempotent).
    @if ($hasManagedServers)
        Aktif: {{ $servers->where('is_active', true)->count() }} dari {{ $servers->count() }} server terdaftar.
    @endif
</p>

@unless ($envEnabled)
    <div class="mb-4 rounded-xl border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-800 dark:border-amber-900 dark:bg-amber-950/40 dark:text-amber-200">
        <p class="font-semibold">Sinkronisasi HestiaCP dinonaktifkan.</p>
        <p class="mt-1">Isi <code>HESTIA_ENABLED=true</code> di <code>.env</code> server lalu jalankan
            <code>php artisan config:cache</code>. Sinkronisasi tidak akan menghubungi API selama belum diisi.</p>
    </div>
@endunless

@if ($hasManagedServers && $configured)
    <div class="mb-4 rounded-xl border border-blue-200 bg-blue-50 px-4 py-3 text-sm text-blue-800 dark:border-blue-900 dark:bg-blue-950/40 dark:text-blue-200">
        <p class="font-semibold">Server terkelola aktif — variabel <code>HESTIA_*</code> diabaikan.</p>
        <p class="mt-1">Kredensial tiap server diatur di
            <a href="{{ route('hestia.servers.index') }}" class="font-semibold underline">Kelola server</a>.</p>
    </div>
@endif
{{-- Ringkasan paket, kuota, dan status (F4-13) --}}
<div class="mb-6 grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-5">
    @foreach ($summary as $card)
        <div class="rounded-xl border border-slate-200 bg-white px-4 py-3 dark:border-slate-800 dark:bg-slate-900">
            <p class="text-xs uppercase tracking-wide text-slate-500">{{ $card['label'] }}</p>
            <p class="mt-1 text-xl font-semibold {{ $card['alert'] ? 'text-red-600 dark:text-red-400' : '' }}">{{ $card['value'] }}</p>
        </div>
    @endforeach
</div>

{{-- Akun belum dipetakan --}}
<div class="mb-6">
    <h2 class="mb-2 text-sm font-semibold uppercase tracking-wide text-slate-500">
        Belum dipetakan ({{ $unmapped->count() }})
    </h2>
    <div class="overflow-x-auto rounded-xl border border-slate-200 bg-white dark:border-slate-800 dark:bg-slate-900">
        <table class="w-full text-sm">
            <thead>
                <tr class="text-left text-xs uppercase text-slate-500">
                    <th class="px-4 py-3">Domain</th>
                    <th class="px-4 py-3">Server</th>
                    <th class="px-4 py-3">Akun Hestia</th>
                    <th class="px-4 py-3">Paket</th>
                    <th class="px-4 py-3">Kuota disk</th>
                    <th class="px-4 py-3">Status</th>
                    <th class="px-4 py-3">Mulai</th>
                    <th class="px-4 py-3">Petakan ke klien</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($unmapped as $account)
                    <tr class="border-t border-slate-100 dark:border-slate-800">
                        <td class="px-4 py-3 font-medium">{{ $account->domain }}</td>
                        <td class="px-4 py-3 text-slate-500">{{ $account->server?->name ?? 'Environment' }}</td>
                        <td class="px-4 py-3">{{ $account->hestia_user }}</td>
                        <td class="px-4 py-3">{{ $account->plan ?? '—' }}</td>
                        <td class="px-4 py-3 whitespace-nowrap">{{ $mb($account->disk_used) }} / {{ $account->diskQuotaSuffix($account->hasDiskQuota() ? $mb($account->disk_quota) : null) }}</td>
                        <td class="px-4 py-3">{!! $badge($account->statusBadgeClass(), $account->statusLabel()) !!}</td>
                        <td class="px-4 py-3">{{ tgl_id($account->start_date) }}</td>
                        <td class="px-4 py-3">
                            <div class="flex items-center gap-2">
                                <form method="POST" action="{{ route('hestia.accounts.map', $account) }}" class="flex items-center gap-2">
                                    @csrf
                                    @method('PATCH')
                                    <select name="client_id" required class="{{ $inputClass }}">
                                        <option value="">— Pilih klien —</option>
                                        @foreach ($clients as $c)
                                            <option value="{{ $c->id }}">{{ $c->name }}</option>
                                        @endforeach
                                    </select>
                                    <button class="rounded-lg bg-slate-800 px-3 py-2 text-sm font-semibold text-white hover:bg-slate-700 dark:bg-slate-700">Petakan</button>
                                </form>
                                <form method="POST" action="{{ route('hestia.accounts.ignore', $account) }}" onsubmit="return confirm('Abaikan akun ini?')">
                                    @csrf
                                    @method('PATCH')
                                    <button class="text-sm text-slate-500 hover:text-slate-700 dark:hover:text-slate-300">Abaikan</button>
                                </form>
                            </div>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="8" class="px-4 py-8 text-center text-slate-500">Semua akun sudah dipetakan.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>

{{-- Semua akun --}}
<div class="mb-2 flex flex-wrap items-end justify-between gap-3">
    <h2 class="text-sm font-semibold uppercase tracking-wide text-slate-500">Semua akun hasil sinkronisasi</h2>

    {{-- Filter paket, kuota, dan status (F4-13) --}}
    <form method="GET" action="{{ route('hestia.index') }}" class="flex flex-wrap items-end gap-2">
        <label class="text-xs text-slate-500">
            <span class="block">Cari</span>
            <input type="search" name="q" value="{{ request('q') }}" placeholder="domain atau akun"
                   class="{{ $inputClass }} mt-1">
        </label>

        <label class="text-xs text-slate-500">
            <span class="block">Paket</span>
            <select name="plan" class="{{ $inputClass }} mt-1">
                <option value="">Semua paket</option>
                @foreach ($plans as $planName)
                    <option value="{{ $planName }}" @selected(request('plan') === $planName)>{{ $planName }}</option>
                @endforeach
            </select>
        </label>

        <label class="text-xs text-slate-500">
            <span class="block">Status</span>
            <select name="status" class="{{ $inputClass }} mt-1">
                @foreach ($statusFilters as $value => $label)
                    <option value="{{ $value }}" @selected(request('status') === $value)>{{ $label }}</option>
                @endforeach
            </select>
        </label>

        <label class="text-xs text-slate-500">
            <span class="block">Kuota</span>
            <select name="quota" class="{{ $inputClass }} mt-1">
                @foreach ($quotaFilters as $value => $label)
                    <option value="{{ $value }}" @selected(request('quota') === $value)>{{ $label }}</option>
                @endforeach
            </select>
        </label>

        <button class="rounded-lg bg-slate-800 px-3 py-2 text-sm font-semibold text-white hover:bg-slate-700 dark:bg-slate-700">Terapkan</button>

        @if (request()->hasAny(['q', 'plan', 'status', 'quota']))
            <a href="{{ route('hestia.index') }}" class="px-1 py-2 text-sm text-slate-500 hover:text-slate-700 dark:hover:text-slate-300">Reset</a>
        @endif
    </form>
</div>

<div class="overflow-x-auto rounded-xl border border-slate-200 bg-white dark:border-slate-800 dark:bg-slate-900">
    <table class="w-full text-sm">
        <thead>
            <tr class="text-left text-xs uppercase text-slate-500">
                <th class="px-4 py-3">Domain</th>
                <th class="px-4 py-3">Server</th>
                <th class="px-4 py-3">Akun</th>
                <th class="px-4 py-3">Paket</th>
                <th class="px-4 py-3">Pemakaian disk</th>
                <th class="px-4 py-3">Klien</th>
                <th class="px-4 py-3">Layanan</th>
                <th class="px-4 py-3">Pemetaan</th>
                <th class="px-4 py-3">Status</th>
                <th class="px-4 py-3">Terakhir dilihat</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($accounts as $account)
                <tr class="border-t border-slate-100 dark:border-slate-800">
                    <td class="px-4 py-3 font-medium">{{ $account->domain }}</td>
                    <td class="px-4 py-3 text-slate-500">{{ $account->server?->name ?? 'Environment' }}</td>
                    <td class="px-4 py-3">{{ $account->hestia_user }}</td>
                    <td class="px-4 py-3">{{ $account->plan ?? '—' }}</td>
                    <td class="px-4 py-3 whitespace-nowrap">
                        @php $percent = $account->diskUsagePercent(); @endphp
                        <div class="flex items-center gap-2">
                            <div class="h-1.5 w-20 overflow-hidden rounded-full bg-slate-200 dark:bg-slate-700">
                                <div class="h-full rounded-full {{ $diskBar($account) }}" style="width: {{ min(100, $percent ?? 0) }}%"></div>
                            </div>
                            <span class="text-xs text-slate-500">{{ $mb($account->disk_used) }} / {{ $account->diskQuotaSuffix($account->hasDiskQuota() ? $mb($account->disk_quota) : null) }}
                                @if ($percent !== null)
                                    <span class="text-slate-400">({{ $percent }}%)</span>
                                @elseif ($account->hasUnlimitedDiskQuota())
                                    <span class="text-slate-400">(tanpa batas)</span>
                                @endif
                            </span>
                        </div>
                        @if ($account->isSuspended() && $account->user_suspended)
                            <span class="mt-1 block text-xs text-amber-700 dark:text-amber-300">Akun Hestia disuspend</span>
                        @endif
                    </td>
                    <td class="px-4 py-3">
                        @if ($account->client)
                            <a href="{{ route('clients.show', $account->client) }}" class="text-indigo-600 hover:underline">{{ $account->client->name }}</a>
                        @else
                            —
                        @endif
                    </td>
                    <td class="px-4 py-3">
                        @if ($account->service)
                            <a href="{{ route('services.show', $account->service) }}" class="text-indigo-600 hover:underline">{{ $account->service->name }}</a>
                        @else
                            —
                        @endif
                    </td>
                    <td class="px-4 py-3">
                        @php
                            $mappingClass = match ($account->mapping_status->value) {
                                'auto', 'mapped' => 'bg-green-100 text-green-700 dark:bg-green-900 dark:text-green-200',
                                'ignored' => 'bg-slate-200 text-slate-600 dark:bg-slate-700 dark:text-slate-300',
                                default => 'bg-amber-100 text-amber-700 dark:bg-amber-900 dark:text-amber-200',
                            };
                        @endphp
                        {!! $badge($mappingClass, $account->mapping_status->label()) !!}
                    </td>
                    <td class="px-4 py-3">{!! $badge($account->statusBadgeClass(), $account->statusLabel()) !!}</td>
                    <td class="px-4 py-3 text-slate-500">{{ $account->last_seen_at?->format('d/m/Y H:i') ?? '—' }}</td>
                </tr>
            @empty
                <tr>
                    <td colspan="10" class="px-4 py-8 text-center text-slate-500">
                        @if (request()->hasAny(['q', 'plan', 'status', 'quota']))
                            Tidak ada akun yang cocok dengan filter.
                        @else
                            Belum ada akun tersinkron.
                        @endif
                    </td>
                </tr>
            @endforelse
        </tbody>
    </table>
</div>
<div class="mt-4">{{ $accounts->links() }}</div>

{{-- Riwayat sinkronisasi --}}
<h2 class="mt-6 mb-2 text-sm font-semibold uppercase tracking-wide text-slate-500">Riwayat sinkronisasi</h2>
<div class="overflow-x-auto rounded-xl border border-slate-200 bg-white dark:border-slate-800 dark:bg-slate-900">
    <table class="w-full text-sm">
        <thead>
            <tr class="text-left text-xs uppercase text-slate-500">
                <th class="px-4 py-3">Waktu</th>
                <th class="px-4 py-3">Sumber</th>
                <th class="px-4 py-3">Status</th>
                <th class="px-4 py-3">Ditarik</th>
                <th class="px-4 py-3">Baru</th>
                <th class="px-4 py-3">Diperbarui</th>
                <th class="px-4 py-3">Nonaktif</th>
                <th class="px-4 py-3">Belum dipetakan</th>
                <th class="px-4 py-3">Pesan</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($logs as $log)
                <tr class="border-t border-slate-100 dark:border-slate-800">
                    <td class="px-4 py-3 text-slate-500">{{ ($log->finished_at ?? $log->started_at)?->format('d/m/Y H:i') ?? '—' }}</td>
                    <td class="px-4 py-3 text-slate-500">{{ $log->sourceLabel() }}</td>
                    <td class="px-4 py-3">
                        {!! $log->isSuccess()
                            ? $badge('bg-green-100 text-green-700 dark:bg-green-900 dark:text-green-200', 'Sukses')
                            : $badge('bg-red-100 text-red-700 dark:bg-red-900 dark:text-red-200', ucfirst($log->status)) !!}
                    </td>
                    <td class="px-4 py-3">{{ $log->pulled }}</td>
                    <td class="px-4 py-3">{{ $log->created }}</td>
                    <td class="px-4 py-3">{{ $log->updated }}</td>
                    <td class="px-4 py-3">{{ $log->deactivated }}</td>
                    <td class="px-4 py-3">{{ $log->unmapped }}</td>
                    <td class="px-4 py-3 text-slate-500">{{ $log->message ?? '—' }}</td>
                </tr>
            @empty
                <tr><td colspan="9" class="px-4 py-8 text-center text-slate-500">Belum ada riwayat sinkronisasi.</td></tr>
            @endforelse
        </tbody>
    </table>
</div>
@endsection
