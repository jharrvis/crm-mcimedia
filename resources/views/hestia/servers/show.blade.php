@extends('layouts.app')

@section('title', 'Detail Server Hestia')

@section('content')
@php
    $accountStatusVariant = fn ($account) => $account->status->value === 'active' ? 'success' : 'slate';
@endphp

<x-page-header :title="$server->name" icon="server"
               back="{{ route('hestia.servers.index') }}" backLabel="Kembali ke daftar server">
    <button type="button"
            data-hestia-sync
            data-sync-start-url="{{ route('hestia.servers.sync.start', $server) }}"
            data-sync-batch-url="{{ route('hestia.servers.sync.batch', $server) }}"
            data-sync-server="{{ $server->name }}"
            class="inline-flex items-center gap-2 rounded-xl bg-brand-600 px-4 py-2.5 text-sm font-semibold text-white shadow-blue-500/20 transition hover:bg-brand-700 disabled:pointer-events-none disabled:opacity-50">
        <i data-lucide="refresh-cw" class="h-4 w-4 shrink-0"></i>
        Sinkronkan server ini
    </button>
    <noscript>
        <form method="POST" action="{{ route('hestia.servers.sync', $server) }}">
            @csrf
            <x-btn type="submit" icon="refresh-cw">Sinkronkan server ini</x-btn>
        </form>
    </noscript>
    <form method="POST" action="{{ route('hestia.servers.test', $server) }}">
        @csrf
        <x-btn type="submit" variant="outline" icon="eye">Uji koneksi</x-btn>
    </form>
    <x-btn :href="route('hestia.servers.edit', $server)" variant="dark">Ubah</x-btn>
</x-page-header>

@include('hestia.servers._sync_progress')

{{-- Ringkasan --}}
<x-card class="mb-6">
    <x-slot:header>
        <h2 class="font-bold">{{ $server->name }}</h2>
        @if ($server->is_active)
            <x-badge variant="success" :dot="true">aktif</x-badge>
        @else
            <x-badge variant="slate">nonaktif</x-badge>
        @endif
        @if ($server->last_sync_status === \App\Domains\Hestia\Models\HestiaServer::STATUS_FAILED)
            <x-badge variant="danger">sync terakhir gagal</x-badge>
        @endif
    </x-slot:header>

    <dl class="grid gap-3 text-sm sm:grid-cols-2 lg:grid-cols-3">
        <div>
            <dt class="text-xs uppercase text-slate-400">Kode</dt>
            <dd><code>{{ $server->code }}</code></dd>
        </div>
        <div>
            <dt class="text-xs uppercase text-slate-400">Endpoint</dt>
            <dd>{{ $server->scheme }}://{{ $server->host }}:{{ $server->port }}</dd>
        </div>
        <div>
            <dt class="text-xs uppercase text-slate-400">Endpoint Netdata</dt>
            <dd>
                @if ($server->netdata_host)
                    http://{{ $server->netdata_host }}:{{ $server->netdata_port ?? 19999 }}
                @else
                    <span class="text-slate-400">belum dikonfigurasi</span>
                @endif
            </dd>
        </div>
        <div>
            <dt class="text-xs uppercase text-slate-400">SSL</dt>
            <dd>{{ $server->verify_ssl ? 'Diverifikasi' : 'Tidak diverifikasi' }}</dd>
        </div>
        <div>
            <dt class="text-xs uppercase text-slate-400">Kredensial</dt>
            <dd>
                @if ($server->isConfigured())
                    Terisi ({{ array_key_exists('access_key', $server->credentialBag()) ? 'access/secret key' : 'user/password' }})
                @else
                    <span class="text-amber-600">Belum lengkap</span>
                @endif
            </dd>
        </div>
        <div>
            <dt class="text-xs uppercase text-slate-400">Sync terakhir</dt>
            <dd>{{ $server->last_sync_at?->format('d/m/Y H:i') ?? '—' }}</dd>
        </div>
        <div>
            <dt class="text-xs uppercase text-slate-400">Percobaan terakhir</dt>
            <dd>{{ $server->last_synced_at?->format('d/m/Y H:i') ?? '—' }}</dd>
        </div>
        @if ($server->notes)
            <div class="sm:col-span-2 lg:col-span-3">
                <dt class="text-xs uppercase text-slate-400">Catatan</dt>
                <dd>{{ $server->notes }}</dd>
            </div>
        @endif
        @if ($server->last_sync_message)
            <div class="sm:col-span-2 lg:col-span-3">
                <dt class="text-xs uppercase text-slate-400">Pesan sync terakhir</dt>
                <dd class="text-slate-400">{{ $server->last_sync_message }}</dd>
            </div>
        @endif
    </dl>
</x-card>

{{-- Akun milik server ini --}}
<h2 class="mb-2 text-sm font-semibold uppercase tracking-wide text-slate-400">
    Akun dari {{ $server->name }} ({{ $accounts->total() }})
</h2>
<x-card class="mb-6">
    <x-table>
        <thead><tr>
            <th>Domain</th>
            <th>Akun Hestia</th>
            <th>Klien</th>
            <th>Status</th>
            <th>Terakhir dilihat</th>
        </tr></thead>
        <tbody>
            @forelse ($accounts as $account)
                <tr>
                    <td class="font-medium">{{ $account->domain }}</td>
                    <td>{{ $account->hestia_user }}</td>
                    <td>
                        @if ($account->client)
                            <a href="{{ route('clients.show', $account->client) }}" class="text-brand-600 hover:underline">{{ $account->client->name }}</a>
                        @else
                            <span class="text-slate-400">belum dipetakan</span>
                        @endif
                    </td>
                    <td><x-badge :variant="$accountStatusVariant($account)">{{ $account->status->label() }}</x-badge></td>
                    <td class="text-slate-400">{{ $account->last_seen_at?->format('d/m/Y H:i') ?? '—' }}</td>
                </tr>
            @empty
                <tr><td colspan="5">
                    <x-empty-state title="Belum ada akun tersinkron" icon="server"
                                   description="Belum ada akun tersinkron dari server ini." />
                </td></tr>
            @endforelse
        </tbody>
    </x-table>
    @if ($accounts->hasPages())
        <div class="mb-6 mt-4">{{ $accounts->links() }}</div>
    @endif
</x-card>

{{-- Riwayat --}}
<h2 class="mb-2 text-sm font-semibold uppercase tracking-wide text-slate-400">Riwayat sinkronisasi server ini</h2>
<x-card>
    <x-table>
        <thead><tr>
            <th>Waktu</th>
            <th>Status</th>
            <th>Ditarik</th>
            <th>Baru</th>
            <th>Diperbarui</th>
            <th>Nonaktif</th>
            <th>Pesan</th>
        </tr></thead>
        <tbody>
            @forelse ($logs as $log)
                <tr>
                    <td class="text-slate-400">{{ ($log->finished_at ?? $log->started_at)?->format('d/m/Y H:i') ?? '—' }}</td>
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
                    <td class="text-slate-400">{{ $log->message ?? '—' }}</td>
                </tr>
            @empty
                <tr><td colspan="7">
                    <x-empty-state title="Belum ada riwayat" icon="history"
                                   description="Belum ada riwayat sinkronisasi untuk server ini." />
                </td></tr>
            @endforelse
        </tbody>
    </x-table>
</x-card>
@endsection
