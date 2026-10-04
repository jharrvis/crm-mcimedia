@extends('layouts.app')

@section('title', 'Server Hestia')

@section('content')
@php
    use App\Domains\Hestia\Models\HestiaServer;

    $inputClass = 'rounded-lg border border-slate-300 px-3 py-2 text-sm dark:border-slate-700 dark:bg-slate-800';
    $badge = fn (string $classes, string $text) => '<span class="rounded-full px-2 py-0.5 text-xs font-medium '.$classes.'">'.e($text).'</span>';
@endphp

<div class="mb-4 flex flex-wrap items-center justify-between gap-2">
    <div>
        <h1 class="text-lg font-bold">Server HestiaCP</h1>
        <p class="text-sm text-slate-500">
            Setiap server disinkronkan terpisah (mis. sg2, YIARI, PA Salatiga).
            Kredensial disimpan terenkripsi dan tidak pernah ditampilkan kembali.
        </p>
    </div>
    <div class="flex flex-wrap items-center gap-2">
        <a href="{{ route('hestia.index') }}"
           class="rounded-lg border border-slate-300 px-4 py-2 text-sm font-semibold text-slate-700 hover:bg-slate-50 dark:border-slate-700 dark:text-slate-200 dark:hover:bg-slate-800">
            Lihat akun hasil sync
        </a>
        <a href="{{ route('hestia.servers.create') }}"
           class="rounded-lg bg-indigo-600 px-4 py-2 text-sm font-semibold text-white hover:bg-indigo-700">
            Tambah server
        </a>
    </div>
</div>

{{-- Progres sinkronisasi bertahap (AJAX, t_dcccffd9) --}}
@include('hestia.servers._sync_progress')

{{-- Status global --}}
@unless ($environmentEnabled)
    <div class="mb-4 rounded-xl border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-800 dark:border-amber-900 dark:bg-amber-950/40 dark:text-amber-200">
        <p class="font-semibold">Sinkronisasi HestiaCP dinonaktifkan.</p>
        <p class="mt-1">
            Isi <code>HESTIA_ENABLED=true</code> di <code>.env</code> server lalu jalankan
            <code>php artisan config:cache</code>. Sampai itu dilakukan, tombol sinkron &amp; uji
            koneksi tidak akan menghubungi API Hestia mana pun.
        </p>
    </div>
@endunless

@if ($environmentEnabled && ! $servers->isEmpty() && $environmentConfigured)
    <div class="mb-4 rounded-xl border border-blue-200 bg-blue-50 px-4 py-3 text-sm text-blue-800 dark:border-blue-900 dark:bg-blue-950/40 dark:text-blue-200">
        <p class="font-semibold">Sinkronisasi memakai server yang terdaftar di sini.</p>
        <p class="mt-1">
                Oleh karena ada server aktif, variabel <code>HESTIA_*</code> di <code>.env</code>
                diabaikan oleh proses sync. Kosongkan kredensial <code>.env</code> bila sudah tidak dipakai.
            </p>
    </div>
@endif

{{-- Daftar server --}}
<div class="overflow-x-auto rounded-xl border border-slate-200 bg-white dark:border-slate-800 dark:bg-slate-900">
    <table class="w-full text-sm">
        <thead>
            <tr class="text-left text-xs uppercase text-slate-500">
                <th class="px-4 py-3">Server</th>
                <th class="px-4 py-3">Kode</th>
                <th class="px-4 py-3">Endpoint Panel</th>
                <th class="px-4 py-3">Endpoint Netdata</th>
                <th class="px-4 py-3">Kredensial</th>
                <th class="px-4 py-3">Akun</th>
                <th class="px-4 py-3">Sync terakhir</th>
                <th class="px-4 py-3">Status</th>
                <th class="px-4 py-3">Aksi</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($servers as $server)
                <tr class="border-t border-slate-100 dark:border-slate-800">
                    <td class="px-4 py-3">
                        <a href="{{ route('hestia.servers.show', $server) }}" class="font-medium text-indigo-600 hover:underline">
                            {{ $server->name }}
                        </a>
                        @if ($server->notes)
                            <p class="text-xs text-slate-500">{{ $server->notes }}</p>
                        @endif
                    </td>
                    <td class="px-4 py-3"><code class="text-xs">{{ $server->code }}</code></td>
                    <td class="px-4 py-3 text-slate-500">
                        {{ $server->scheme }}://{{ $server->host }}:{{ $server->port }}
                        @unless ($server->verify_ssl)
                            <span class="text-xs text-amber-600">(SSL off)</span>
                        @endunless
                    </td>
                    <td class="px-4 py-3 text-slate-500">
                        @if ($server->netdata_host)
                            http://{{ $server->netdata_host }}:{{ $server->netdata_port ?? 19999 }}
                        @else
                            <span class="text-slate-400">belum dikonfigurasi</span>
                        @endif
                    </td>
                    <td class="px-4 py-3">
                        {!! $server->isConfigured()
                            ? $badge('bg-green-100 text-green-700 dark:bg-green-900 dark:text-green-200', 'terisi')
                            : $badge('bg-amber-100 text-amber-700 dark:bg-amber-900 dark:text-amber-200', 'kosong') !!}
                    </td>
                    <td class="px-4 py-3">{{ $server->accounts_count }}</td>
                    <td class="px-4 py-3 text-slate-500">{{ $server->last_sync_at?->format('d/m/Y H:i') ?? '—' }}</td>
                    <td class="px-4 py-3">
                        @if (! $server->is_active)
                            {!! $badge('bg-slate-200 text-slate-600 dark:bg-slate-700 dark:text-slate-300', 'nonaktif') !!}
                        @elseif ($server->last_sync_status === HestiaServer::STATUS_FAILED)
                            {!! $badge('bg-red-100 text-red-700 dark:bg-red-900 dark:text-red-200', 'sync gagal') !!}
                        @elseif ($server->last_sync_status === HestiaServer::STATUS_SUCCESS)
                            {!! $badge('bg-green-100 text-green-700 dark:bg-green-900 dark:text-green-200', 'aktif') !!}
                        @else
                            {!! $badge('bg-slate-200 text-slate-600 dark:bg-slate-700 dark:text-slate-300', 'aktif') !!}
                        @endif
                    </td>
                    <td class="px-4 py-3">
                        <div class="flex flex-wrap items-center gap-2">
                            {{-- Sinkron bertahap via AJAX (t_dcccffd9): proses dipecah per
                                 batch oleh server sehingga tidak ada request panjang yang
                                 kena gateway timeout. Fallback tanpa JavaScript: form POST lama. --}}
                            <button type="button"
                                    data-hestia-sync
                                    data-sync-start-url="{{ route('hestia.servers.sync.start', $server) }}"
                                    data-sync-batch-url="{{ route('hestia.servers.sync.batch', $server) }}"
                                    data-sync-server="{{ $server->name }}"
                                    class="text-xs font-semibold text-indigo-600 hover:underline disabled:cursor-not-allowed disabled:opacity-50">
                                Sinkron
                            </button>
                            <noscript>
                                <form method="POST" action="{{ route('hestia.servers.sync', $server) }}">
                                    @csrf
                                    <button class="text-xs font-semibold text-indigo-600 hover:underline">Sinkron</button>
                                </form>
                            </noscript>
                            <form method="POST" action="{{ route('hestia.servers.test', $server) }}">
                                @csrf
                                <button class="text-xs font-semibold text-slate-600 hover:underline dark:text-slate-300">Uji</button>
                            </form>
                            <a href="{{ route('hestia.servers.edit', $server) }}"
                               class="text-xs font-semibold text-slate-600 hover:underline dark:text-slate-300">Ubah</a>
                            <form method="POST" action="{{ route('hestia.servers.destroy', $server) }}"
                                  onsubmit="return confirm('Hapus server {{ $server->name }}? Akun hasil sinkronisasi tetap disimpan.')">
                                @csrf
                                @method('DELETE')
                                <button class="text-xs font-semibold text-red-600 hover:underline">Hapus</button>
                            </form>
                        </div>
                    </td>
                </tr>
            @empty
                <tr>
                    <td colspan="8" class="px-4 py-8 text-center text-slate-500">
                        Belum ada server HestiaCP terdaftar. Tambahkan sg2, YIARI, atau PA Salatiga terlebih dahulu.
                    </td>
                </tr>
            @endforelse
        </tbody>
    </table>
</div>

<p class="mt-4 text-xs text-slate-500">
   Server tak aktif tidak ikut dijadwalkan. Hapus server tidak menghapus akun/layanan —
    data hanya kehilangan sumbernya (akun tetap tersimpan di
    <a href="{{ route('hestia.index') }}" class="text-indigo-600 hover:underline">daftar akun</a>).
</p>
@endsection