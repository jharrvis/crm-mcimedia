@extends('layouts.app')

@section('title', 'Detail Server Hestia')

@section('content')
@php
    $inputClass = 'rounded-lg border border-slate-300 px-3 py-2 text-sm dark:border-slate-700 dark:bg-slate-800';
    $badge = fn (string $classes, string $text) => '<span class="rounded-full px-2 py-0.5 text-xs font-medium '.$classes.'">'.e($text).'</span>';
@endphp

<div class="mb-4 flex flex-wrap items-center justify-between gap-2">
    <a href="{{ route('hestia.servers.index') }}" class="text-sm text-brand-600 hover:underline">&larr; Kembali ke daftar server</a>
    <div class="flex items-center gap-2">
        {{-- Sinkron bertahap via AJAX (t_dcccffd9) — fallback tanpa JavaScript
             tetap memakai form POST lama. --}}
        <button type="button"
                data-hestia-sync
                data-sync-start-url="{{ route('hestia.servers.sync.start', $server) }}"
                data-sync-batch-url="{{ route('hestia.servers.sync.batch', $server) }}"
                data-sync-server="{{ $server->name }}"
                class="rounded-lg bg-brand-600 px-4 py-2 text-sm font-semibold text-white hover:bg-brand-700 disabled:cursor-not-allowed disabled:opacity-50">
            Sinkronkan server ini
        </button>
        <noscript>
            <form method="POST" action="{{ route('hestia.servers.sync', $server) }}">
                @csrf
                <button class="rounded-lg bg-brand-600 px-4 py-2 text-sm font-semibold text-white hover:bg-brand-700">
                    Sinkronkan server ini
                </button>
            </form>
        </noscript>
        <form method="POST" action="{{ route('hestia.servers.test', $server) }}">
            @csrf
            <button class="rounded-lg border border-slate-300 px-4 py-2 text-sm font-semibold text-slate-700 hover:bg-slate-50 dark:border-slate-700 dark:text-slate-200">
                Uji koneksi
            </button>
        </form>
        <a href="{{ route('hestia.servers.edit', $server) }}"
           class="rounded-lg border border-slate-300 px-4 py-2 text-sm font-semibold text-slate-700 hover:bg-slate-50 dark:border-slate-700 dark:text-slate-200">
            Ubah
        </a>
    </div>
</div>

{{-- Progres sinkronisasi bertahap (AJAX, t_dcccffd9) --}}
@include('hestia.servers._sync_progress')

{{-- Ringkasan --}}
<div class="mb-6 rounded-xl border border-slate-200 bg-white p-6 dark:border-slate-800 dark:bg-slate-900">
    <div class="mb-4 flex items-center gap-3">
        <h1 class="text-lg font-bold">{{ $server->name }}</h1>
        @if ($server->is_active)
            {!! $badge('bg-green-100 text-green-700 dark:bg-green-900 dark:text-green-200', 'aktif') !!}
        @else
            {!! $badge('bg-slate-200 text-slate-600 dark:bg-slate-700 dark:text-slate-300', 'nonaktif') !!}
        @endif
        @if ($server->last_sync_status === \App\Domains\Hestia\Models\HestiaServer::STATUS_FAILED)
            {!! $badge('bg-red-100 text-red-700 dark:bg-red-900 dark:text-red-200', 'sync terakhir gagal') !!}
        @endif
    </div>

    <dl class="grid gap-3 text-sm sm:grid-cols-2 lg:grid-cols-3">
        <div>
            <dt class="text-xs uppercase text-slate-500">Kode</dt>
            <dd><code>{{ $server->code }}</code></dd>
        </div>
        <div>
            <dt class="text-xs uppercase text-slate-500">Endpoint</dt>
            <dd>{{ $server->scheme }}://{{ $server->host }}:{{ $server->port }}</dd>
        </div>
        <div>
            <dt class="text-xs uppercase text-slate-500">Endpoint Netdata</dt>
            <dd>
                @if ($server->netdata_host)
                    http://{{ $server->netdata_host }}:{{ $server->netdata_port ?? 19999 }}
                @else
                    <span class="text-slate-400">belum dikonfigurasi</span>
                @endif
            </dd>
        </div>
        <div>
            <dt class="text-xs uppercase text-slate-500">SSL</dt>
            <dd>{{ $server->verify_ssl ? 'Diverifikasi' : 'Tidak diverifikasi' }}</dd>
        </div>
        <div>
            <dt class="text-xs uppercase text-slate-500">Kredensial</dt>
            <dd>
                @if ($server->isConfigured())
                    Terisi ({{ array_key_exists('access_key', $server->credentialBag()) ? 'access/secret key' : 'user/password' }})
                @else
                    <span class="text-amber-600">Belum lengkap</span>
                @endif
            </dd>
        </div>
        <div>
            <dt class="text-xs uppercase text-slate-500">Sync terakhir</dt>
            <dd>{{ $server->last_sync_at?->format('d/m/Y H:i') ?? '—' }}</dd>
        </div>
        <div>
            <dt class="text-xs uppercase text-slate-500">Percobaan terakhir</dt>
            <dd>{{ $server->last_synced_at?->format('d/m/Y H:i') ?? '—' }}</dd>
        </div>
        @if ($server->notes)
            <div class="sm:col-span-2 lg:col-span-3">
                <dt class="text-xs uppercase text-slate-500">Catatan</dt>
                <dd>{{ $server->notes }}</dd>
            </div>
        @endif
        @if ($server->last_sync_message)
            <div class="sm:col-span-2 lg:col-span-3">
                <dt class="text-xs uppercase text-slate-500">Pesan sync terakhir</dt>
                <dd class="text-slate-600">{{ $server->last_sync_message }}</dd>
            </div>
        @endif
    </dl>
</div>

{{-- Akun milik server ini --}}
<h2 class="mb-2 text-sm font-semibold uppercase tracking-wide text-slate-500">
    Akun dari {{ $server->name }} ({{ $accounts->total() }})
</h2>
<div class="mb-6 overflow-x-auto rounded-xl border border-slate-200 bg-white dark:border-slate-800 dark:bg-slate-900">
    <table class="w-full text-sm">
        <thead>
            <tr class="text-left text-xs uppercase text-slate-500">
                <th class="px-4 py-3">Domain</th>
                <th class="px-4 py-3">Akun Hestia</th>
                <th class="px-4 py-3">Klien</th>
                <th class="px-4 py-3">Status</th>
                <th class="px-4 py-3">Terakhir dilihat</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($accounts as $account)
                <tr class="border-t border-slate-100 dark:border-slate-800">
                    <td class="px-4 py-3 font-medium">{{ $account->domain }}</td>
                    <td class="px-4 py-3">{{ $account->hestia_user }}</td>
                    <td class="px-4 py-3">
                        @if ($account->client)
                            <a href="{{ route('clients.show', $account->client) }}" class="text-brand-600 hover:underline">{{ $account->client->name }}</a>
                        @else
                            <span class="text-slate-500">belum dipetakan</span>
                        @endif
                    </td>
                    <td class="px-4 py-3">
                        {!! $account->status->value === 'active'
                            ? $badge('bg-green-100 text-green-700 dark:bg-green-900 dark:text-green-200', $account->status->label())
                            : $badge('bg-slate-200 text-slate-600 dark:bg-slate-700 dark:text-slate-300', $account->status->label()) !!}
                    </td>
                    <td class="px-4 py-3 text-slate-500">{{ $account->last_seen_at?->format('d/m/Y H:i') ?? '—' }}</td>
                </tr>
            @empty
                <tr><td colspan="5" class="px-4 py-8 text-center text-slate-500">Belum ada akun tersinkron dari server ini.</td></tr>
            @endforelse
        </tbody>
    </table>
</div>
<div class="mb-6">{{ $accounts->links() }}</div>

{{-- Riwayat --}}
<h2 class="mb-2 text-sm font-semibold uppercase tracking-wide text-slate-500">Riwayat sinkronisasi server ini</h2>
<div class="overflow-x-auto rounded-xl border border-slate-200 bg-white dark:border-slate-800 dark:bg-slate-900">
    <table class="w-full text-sm">
        <thead>
            <tr class="text-left text-xs uppercase text-slate-500">
                <th class="px-4 py-3">Waktu</th>
                <th class="px-4 py-3">Status</th>
                <th class="px-4 py-3">Ditarik</th>
                <th class="px-4 py-3">Baru</th>
                <th class="px-4 py-3">Diperbarui</th>
                <th class="px-4 py-3">Nonaktif</th>
                <th class="px-4 py-3">Pesan</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($logs as $log)
                <tr class="border-t border-slate-100 dark:border-slate-800">
                    <td class="px-4 py-3 text-slate-500">{{ ($log->finished_at ?? $log->started_at)?->format('d/m/Y H:i') ?? '—' }}</td>
                    <td class="px-4 py-3">
                        {!! $log->isSuccess()
                            ? $badge('bg-green-100 text-green-700 dark:bg-green-900 dark:text-green-200', 'Sukses')
                            : $badge('bg-red-100 text-red-700 dark:bg-red-900 dark:text-red-200', ucfirst($log->status)) !!}
                    </td>
                    <td class="px-4 py-3">{{ $log->pulled }}</td>
                    <td class="px-4 py-3">{{ $log->created }}</td>
                    <td class="px-4 py-3">{{ $log->updated }}</td>
                    <td class="px-4 py-3">{{ $log->deactivated }}</td>
                    <td class="px-4 py-3 text-slate-500">{{ $log->message ?? '—' }}</td>
                </tr>
            @empty
                <tr><td colspan="7" class="px-4 py-8 text-center text-slate-500">Belum ada riwayat sinkronisasi untuk server ini.</td></tr>
            @endforelse
        </tbody>
    </table>
</div>
@endsection