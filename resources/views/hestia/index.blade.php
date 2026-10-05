@extends('layouts.app')

@section('title', 'Sinkron Hestia')

@section('content')
@php
    // Model hanya menyediakan statusBadgeClass() (CSS class) — mapping ke
    // varian x-badge dilakukan di view, tanpa menyentuh model.
    $statusVariant = fn ($account) => match (true) {
        $account->isSuspended() => 'warning',
        $account->status->value === 'active' => 'success',
        default => 'slate',
    };
    $mappingVariant = fn ($account) => match ($account->mapping_status->value) {
        'auto', 'mapped' => 'success',
        'ignored' => 'slate',
        default => 'warning',
    };

    $mb = function (?int $megabytes): string {
        if ($megabytes === null) {
            return '—';
        }

        if ($megabytes >= 1024) {
            return number_format($megabytes / 1024, 1, ',', '.').' GB';
        }

        return $megabytes.' MB';
    };

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

    $serverFilterOptions = ['' => '— Pilih —', 'all' => 'Semua server'];
    foreach ($servers as $s) {
        $serverFilterOptions['srv:'.$s->id] = $s->name.($s->is_active ? '' : ' (nonaktif)');
    }
    $serverFilterOptions['env'] = 'Environment (.env)';
@endphp

<x-page-header title="Sinkron Hestia" icon="server"
               subtitle="Menarik akun hosting/domain dari HestiaCP (read-only, idempotent).{{ $hasManagedServers ? ' Aktif: ' . $servers->where('is_active', true)->count() . ' dari ' . $servers->count() . ' server terdaftar.' : '' }}">
    <form method="POST" action="{{ route('hestia.sync') }}">
        @csrf
        <x-btn type="submit" icon="refresh-cw">Sinkronkan semua server</x-btn>
    </form>
    <x-btn :href="route('hestia.servers.index')" variant="outline">Kelola server</x-btn>

    @if ($servers->isNotEmpty())
        <form method="GET" action="{{ route('hestia.index') }}" class="flex items-center gap-2">
            <x-input name="server" type="select" :options="$serverFilterOptions" :value="$serverFilter"
                     inputId="server" onchange="this.form.submit()" class="mb-0" label="Server" />
            <noscript><x-btn type="submit" variant="outline">Terapkan</x-btn></noscript>
        </form>
    @endif
</x-page-header>

@unless ($envEnabled)
    <x-card class="mb-4 border-amber-200 dark:border-amber-900">
        <p class="text-sm font-semibold text-amber-800 dark:text-amber-200">Sinkronisasi HestiaCP dinonaktifkan.</p>
        <p class="mt-1 text-sm text-amber-800 dark:text-amber-200">Isi <code>HESTIA_ENABLED=true</code> di <code>.env</code> server lalu jalankan
            <code>php artisan config:cache</code>. Sinkronisasi tidak akan menghubungi API selama belum diisi.</p>
    </x-card>
@endunless

@if ($hasManagedServers && $configured)
    <x-card class="mb-4 border-blue-200 dark:border-blue-900">
        <p class="text-sm font-semibold text-blue-800 dark:text-blue-200">Server terkelola aktif — variabel <code>HESTIA_*</code> diabaikan.</p>
        <p class="mt-1 text-sm text-blue-800 dark:text-blue-200">Kredensial tiap server diatur di
            <a href="{{ route('hestia.servers.index') }}" class="font-semibold underline">Kelola server</a>.</p>
    </x-card>
@endif

<div class="mb-6 grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-5">
    @foreach ($summary as $card)
        <x-stat-card :label="$card['label']" :value="$card['value']" />
    @endforeach
</div>

<h2 class="mb-2 text-sm font-semibold uppercase tracking-wide text-slate-400">
    Belum dipetakan ({{ $unmapped->count() }})
</h2>
<x-card class="mb-6">
    <x-table>
        <thead><tr>
            <th>Domain</th>
            <th>Server</th>
            <th>Akun Hestia</th>
            <th>Paket</th>
            <th>Kuota disk</th>
            <th>Status</th>
            <th>Mulai</th>
            <th>Petakan ke klien</th>
        </tr></thead>
        <tbody>
            @forelse ($unmapped as $account)
                @php
                    $clientOptions = ['' => '— Pilih klien —'] + collect($clients)->mapWithKeys(fn ($c) => [$c->id => $c->name])->all();
                @endphp
                <tr>
                    <td class="font-medium">{{ $account->domain }}</td>
                    <td class="text-slate-400">{{ $account->server?->name ?? 'Environment' }}</td>
                    <td>{{ $account->hestia_user }}</td>
                    <td>{{ $account->plan ?? '—' }}</td>
                    <td class="whitespace-nowrap">{{ $mb($account->disk_used) }} / {{ $account->diskQuotaSuffix($account->hasDiskQuota() ? $mb($account->disk_quota) : null) }}</td>
                    <td><x-badge :variant="$statusVariant($account)">{{ $account->statusLabel() }}</x-badge></td>
                    <td>{{ tgl_id($account->start_date) }}</td>
                    <td>
                        <div class="flex items-center gap-2">
                            <form method="POST" action="{{ route('hestia.accounts.map', $account) }}" class="flex items-center gap-2">
                                @csrf
                                @method('PATCH')
                                <x-input name="client_id" type="select" :options="$clientOptions" :required="true" class="mb-0" />
                                <x-btn type="submit" variant="dark">Petakan</x-btn>
                            </form>
                            <form method="POST" action="{{ route('hestia.accounts.ignore', $account) }}" onsubmit="return confirm('Abaikan akun ini?')">
                                @csrf
                                @method('PATCH')
                                <button type="submit" class="text-sm text-slate-400 hover:text-slate-700 dark:hover:text-slate-200">Abaikan</button>
                            </form>
                        </div>
                    </td>
                </tr>
            @empty
                <tr><td colspan="8">
                    <x-empty-state title="Semua akun sudah dipetakan" icon="server" />
                </td></tr>
            @endforelse
        </tbody>
    </x-table>
</x-card>

<div class="mb-2 flex flex-wrap items-end justify-between gap-3">
    <h2 class="text-sm font-semibold uppercase tracking-wide text-slate-400">Semua akun hasil sinkronisasi</h2>

    <form method="GET" action="{{ route('hestia.index') }}" class="flex flex-wrap items-end gap-2">
        <x-input name="q" label="Cari" type="search" placeholder="domain atau akun" :value="request('q')" class="mb-0" />

        <x-input name="plan" label="Paket" type="select"
                 :options="['' => 'Semua paket'] + collect($plans)->mapWithKeys(fn ($p) => [$p => $p])->all()"
                 :value="request('plan')" class="mb-0" />

        <x-input name="status" label="Status" type="select" :options="$statusFilters" :value="request('status')" class="mb-0" />
        <x-input name="quota" label="Kuota" type="select" :options="$quotaFilters" :value="request('quota')" class="mb-0" />

        <x-btn type="submit" variant="dark">Terapkan</x-btn>

        @if (request()->hasAny(['q', 'plan', 'status', 'quota']))
            <a href="{{ route('hestia.index') }}" class="px-1 py-2 text-sm text-slate-400 hover:text-slate-600 dark:hover:text-slate-200">Reset</a>
        @endif
    </form>
</div>

<x-card>
    <x-table>
        <thead><tr>
            <th>Domain</th>
            <th>Server</th>
            <th>Akun</th>
            <th>Paket</th>
            <th>Pemakaian disk</th>
            <th>Klien</th>
            <th>Layanan</th>
            <th>Pemetaan</th>
            <th>Status</th>
            <th>Terakhir dilihat</th>
        </tr></thead>
        <tbody>
            @forelse ($accounts as $account)
                @php
                    $percent = $account->diskUsagePercent();
                    $dq = $account->hasDiskQuota() ? $mb($account->disk_quota) : null;
                @endphp
                <tr>
                    <td class="font-medium">{{ $account->domain }}</td>
                    <td class="text-slate-400">{{ $account->server?->name ?? 'Environment' }}</td>
                    <td>{{ $account->hestia_user }}</td>
                    <td>{{ $account->plan ?? '—' }}</td>
                    <td class="whitespace-nowrap">
                        <div class="flex items-center gap-2">
                            <div class="h-1.5 w-20 overflow-hidden rounded-full bg-slate-200 dark:bg-slate-700">
                                <div class="h-full rounded-full {{ $diskBar($account) }}" style="width: {{ min(100, $percent ?? 0) }}%"></div>
                            </div>
                            <span class="text-xs text-slate-400">{{ $mb($account->disk_used) }} / {{ $account->diskQuotaSuffix($dq) }}
                                @if ($percent !== null)
                                    <span>({{ $percent }}%)</span>
                                @elseif ($account->hasUnlimitedDiskQuota())
                                    <span>(tanpa batas)</span>
                                @endif
                            </span>
                        </div>
                        @if ($account->isSuspended() && $account->user_suspended)
                            <p class="mt-1 text-xs font-semibold text-amber-700 dark:text-amber-300">Akun Hestia disuspend</p>
                        @endif
                    </td>
                    <td>
                        @if ($account->client)
                            <a href="{{ route('clients.show', $account->client) }}" class="text-brand-600 hover:underline">{{ $account->client->name }}</a>
                        @else
                            —
                        @endif
                    </td>
                    <td>
                        @if ($account->service)
                            <a href="{{ route('services.show', $account->service) }}" class="text-brand-600 hover:underline">{{ $account->service->name }}</a>
                        @else
                            —
                        @endif
                    </td>
                    <td><x-badge :variant="$mappingVariant($account)">{{ $account->mapping_status->label() }}</x-badge></td>
                    <td><x-badge :variant="$statusVariant($account)">{{ $account->statusLabel() }}</x-badge></td>
                    <td class="text-slate-400">{{ $account->last_seen_at?->format('d/m/Y H:i') ?? '—' }}</td>
                </tr>
            @empty
                <tr><td colspan="10">
                    <x-empty-state title="Belum ada akun tersinkron" icon="server"
                                   :description="request()->hasAny(['q', 'plan', 'status', 'quota'])
                                       ? 'Tidak ada akun yang cocok dengan filter.'
                                       : 'Belum ada akun tersinkron.'" />
                </td></tr>
            @endforelse
        </tbody>
    </x-table>
    @if ($accounts->hasPages())
        <div class="mt-4">{{ $accounts->links() }}</div>
    @endif
</x-card>

<h2 class="mb-2 mt-6 text-sm font-semibold uppercase tracking-wide text-slate-400">Riwayat sinkronisasi</h2>
<x-card>
    <x-table>
        <thead><tr>
            <th>Waktu</th>
            <th>Sumber</th>
            <th>Status</th>
            <th>Ditarik</th>
            <th>Baru</th>
            <th>Diperbarui</th>
            <th>Nonaktif</th>
            <th>Belum dipetakan</th>
            <th>Pesan</th>
        </tr></thead>
        <tbody>
            @forelse ($logs as $log)
                <tr>
                    <td class="text-slate-400">{{ ($log->finished_at ?? $log->started_at)?->format('d/m/Y H:i') ?? '—' }}</td>
                    <td class="text-slate-400">{{ $log->sourceLabel() }}</td>
                    <td>
                        @if ($log->isSuccess())
                            <x-badge variant="success">Sukses</x-badge>
                        @else
                            <x-badge variant="danger">{{ ucfirst($log->status) }}</x-badge>
                        @endif
                    </td>
                    <td>{{ $log->pulled }}</td>
                    <td>{{ $log->created }}</td>
                    <td>{{ $log->updated }}</td>
                    <td>{{ $log->deactivated }}</td>
                    <td>{{ $log->unmapped }}</td>
                    <td class="text-slate-400">{{ $log->message ?? '—' }}</td>
                </tr>
            @empty
                <tr><td colspan="9">
                    <x-empty-state title="Belum ada riwayat sinkronisasi" icon="history" />
                </td></tr>
            @endforelse
        </tbody>
    </x-table>
</x-card>
@endsection
