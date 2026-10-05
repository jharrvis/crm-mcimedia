@extends('layouts.app')

@section('title', 'Server Hestia')

@section('content')
@php
    use App\Domains\Hestia\Models\HestiaServer;

    $statusVariant = fn ($server) => match (true) {
        ! $server->is_active => 'slate',
        $server->last_sync_status === HestiaServer::STATUS_FAILED => 'danger',
        $server->last_sync_status === HestiaServer::STATUS_SUCCESS => 'success',
        default => 'slate',
    };
    $statusLabel = fn ($server) => match (true) {
        ! $server->is_active => 'nonaktif',
        $server->last_sync_status === HestiaServer::STATUS_FAILED => 'sync gagal',
        $server->last_sync_status === HestiaServer::STATUS_SUCCESS => 'aktif',
        default => 'aktif',
    };
    $credentialVariant = fn ($server) => $server->isConfigured() ? 'success' : 'warning';
@endphp

<x-page-header title="Server HestiaCP" icon="server"
               subtitle="Setiap server disinkronkan terpisah (mis. sg2, YIARI, PA Salatiga). Kredensial disimpan terenkripsi dan tidak pernah ditampilkan kembali.">
    <x-btn :href="route('hestia.index')" variant="outline">Lihat akun hasil sync</x-btn>
    <x-btn :href="route('hestia.servers.create')" icon="plus">Tambah server</x-btn>
</x-page-header>

@include('hestia.servers._sync_progress')

@unless ($environmentEnabled)
    <x-card class="mb-4 border-amber-200 dark:border-amber-900">
        <p class="text-sm font-semibold text-amber-800 dark:text-amber-200">Sinkronisasi HestiaCP dinonaktifkan.</p>
        <p class="mt-1 text-sm text-amber-800 dark:text-amber-200">
            Isi <code>HESTIA_ENABLED=true</code> di <code>.env</code> server lalu jalankan
            <code>php artisan config:cache</code>. Sampai itu dilakukan, tombol sinkron &amp; uji
            koneksi tidak akan menghubungi API Hestia mana pun.
        </p>
    </x-card>
@endunless

@if ($environmentEnabled && ! $servers->isEmpty() && $environmentConfigured)
    <x-card class="mb-4 border-blue-200 dark:border-blue-900">
        <p class="text-sm font-semibold text-blue-800 dark:text-blue-200">Sinkronisasi memakai server yang terdaftar di sini.</p>
        <p class="mt-1 text-sm text-blue-800 dark:text-blue-200">
            Oleh karena ada server aktif, variabel <code>HESTIA_*</code> di <code>.env</code>
            diabaikan oleh proses sync. Kosongkan kredensial <code>.env</code> bila sudah tidak dipakai.
        </p>
    </x-card>
@endif

<x-card>
    <x-table>
        <thead><tr>
            <th>Server</th>
            <th>Kode</th>
            <th>Endpoint Panel</th>
            <th>Endpoint Netdata</th>
            <th>Kredensial</th>
            <th>Akun</th>
            <th>Sync terakhir</th>
            <th>Status</th>
            <th>Aksi</th>
        </tr></thead>
        <tbody>
            @forelse ($servers as $server)
                <tr>
                    <td>
                        <a href="{{ route('hestia.servers.show', $server) }}" class="font-semibold text-brand-600 hover:underline">
                            {{ $server->name }}
                        </a>
                        @if ($server->notes)
                            <p class="text-xs text-slate-400">{{ $server->notes }}</p>
                        @endif
                    </td>
                    <td><code class="text-xs">{{ $server->code }}</code></td>
                    <td class="text-slate-400">
                        {{ $server->scheme }}://{{ $server->host }}:{{ $server->port }}
                        @unless ($server->verify_ssl)
                            <span class="text-xs text-amber-600">(SSL off)</span>
                        @endunless
                    </td>
                    <td class="text-slate-400">
                        @if ($server->netdata_host)
                            http://{{ $server->netdata_host }}:{{ $server->netdata_port ?? 19999 }}
                        @else
                            <span class="text-slate-400">belum dikonfigurasi</span>
                        @endif
                    </td>
                    <td><x-badge :variant="$credentialVariant($server)">{{ $server->isConfigured() ? 'terisi' : 'kosong' }}</x-badge></td>
                    <td>{{ $server->accounts_count }}</td>
                    <td class="text-slate-400">{{ $server->last_sync_at?->format('d/m/Y H:i') ?? '—' }}</td>
                    <td><x-badge :variant="$statusVariant($server)">{{ $statusLabel($server) }}</x-badge></td>
                    <td>
                        <div class="flex flex-wrap items-center gap-2">
                            <button type="button"
                                    data-hestia-sync
                                    data-sync-start-url="{{ route('hestia.servers.sync.start', $server) }}"
                                    data-sync-batch-url="{{ route('hestia.servers.sync.batch', $server) }}"
                                    data-sync-server="{{ $server->name }}"
                                    class="text-xs font-semibold text-brand-600 hover:underline disabled:cursor-not-allowed disabled:opacity-50">
                                Sinkron
                            </button>
                            <noscript>
                                <form method="POST" action="{{ route('hestia.servers.sync', $server) }}">
                                    @csrf
                                    <button class="text-xs font-semibold text-brand-600 hover:underline">Sinkron</button>
                                </form>
                            </noscript>
                            <form method="POST" action="{{ route('hestia.servers.test', $server) }}">
                                @csrf
                                <button class="text-xs font-semibold text-slate-400 hover:text-slate-600 dark:hover:text-slate-200">Uji</button>
                            </form>
                            <a href="{{ route('hestia.servers.edit', $server) }}"
                               class="text-xs font-semibold text-slate-400 hover:text-slate-600 dark:hover:text-slate-200">Ubah</a>
                            <form method="POST" action="{{ route('hestia.servers.destroy', $server) }}"
                                  onsubmit="return confirm('Hapus server {{ $server->name }}? Akun hasil sinkronisasi tetap disimpan.')">
                                @csrf
                                @method('DELETE')
                                <button class="text-xs font-semibold text-rose-600 hover:underline">Hapus</button>
                            </form>
                        </div>
                    </td>
                </tr>
            @empty
                <tr><td colspan="9">
                    <x-empty-state title="Belum ada server HestiaCP" icon="server"
                                   description="Tambahkan sg2, YIARI, atau PA Salatiga terlebih dahulu.">
                        <x-slot:action>
                            <x-btn :href="route('hestia.servers.create')" icon="plus">Tambah server</x-btn>
                        </x-slot:action>
                    </x-empty-state>
                </td></tr>
            @endforelse
        </tbody>
    </x-table>

    @if ($servers->hasPages())
        <div class="mt-4">{{ $servers->links() }}</div>
    @endif
</x-card>

<p class="mt-4 text-xs text-slate-400">
   Server tak aktif tidak ikut dijadwalkan. Hapus server tidak menghapus akun/layanan —
   data hanya kehilangan sumbernya (akun tetap tersimpan di
   <a href="{{ route('hestia.index') }}" class="text-brand-600 hover:underline">daftar akun</a>).
</p>
@endsection
