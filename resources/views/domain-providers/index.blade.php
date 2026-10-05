@extends('layouts.app')

@section('title', 'Provider Domain')

@section('content')
<div class="mb-4 flex flex-wrap items-center gap-2">
    <a href="{{ route('domain-providers.create') }}" class="rounded-lg bg-brand-600 px-4 py-2 text-sm font-semibold text-white hover:bg-brand-700">Tambah provider</a>
    <span class="text-sm text-slate-500">Registry penyedia domain/hosting — kredensial tersimpan terenkripsi.</span>
</div>

<form method="GET" action="{{ route('domain-providers.index') }}" class="mb-4 grid gap-2 rounded-xl border border-slate-200 bg-white p-4 sm:grid-cols-3 dark:border-slate-800 dark:bg-slate-900">
    <input name="q" value="{{ request('q') }}" placeholder="Cari nama / driver…" class="rounded-lg border border-slate-300 px-3 py-2 text-sm dark:border-slate-700 dark:bg-slate-800">
    <select name="status" class="rounded-lg border border-slate-300 px-3 py-2 text-sm dark:border-slate-700 dark:bg-slate-800">
        <option value="all" @selected($statusFilter === 'all')>Semua status</option>
        <option value="active" @selected($statusFilter === 'active')>Aktif</option>
        <option value="inactive" @selected($statusFilter === 'inactive')>Nonaktif</option>
    </select>
    <button class="rounded-lg bg-slate-800 px-4 py-2 text-sm font-semibold text-white hover:bg-slate-700 dark:bg-slate-700">Filter</button>
</form>

<div class="overflow-x-auto rounded-xl border border-slate-200 bg-white dark:border-slate-800 dark:bg-slate-900">
    <table class="w-full text-sm">
        <thead>
            <tr class="text-left text-xs uppercase text-slate-500">
                <th class="px-4 py-3">Nama</th>
                <th class="px-4 py-3">Driver</th>
                <th class="px-4 py-3">Catatan</th>
                <th class="px-4 py-3">Status</th>
                <th class="px-4 py-3">Terakhir dipakai</th>
                <th class="px-4 py-3 text-right">Aksi</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($providers as $provider)
                <tr class="border-t border-slate-100 dark:border-slate-800">
                    <td class="px-4 py-3 font-medium">{{ $provider->name }}</td>
                    <td class="px-4 py-3">
                        <span class="rounded-full bg-slate-100 px-2 py-0.5 text-xs font-medium text-slate-700 dark:bg-slate-800 dark:text-slate-300">
                            {{ $drivers[$provider->driver] ?? $provider->driver }}
                        </span>
                    </td>
                    <td class="px-4 py-3 text-slate-500">{{ $provider->notes ?? '—' }}</td>
                    <td class="px-4 py-3">
                        <span class="rounded-full px-2 py-0.5 text-xs font-medium {{ $provider->is_active ? 'bg-green-100 text-green-700 dark:bg-green-900 dark:text-green-200' : 'bg-slate-200 text-slate-600 dark:bg-slate-700 dark:text-slate-300' }}">
                            {{ $provider->is_active ? 'Aktif' : 'Nonaktif' }}
                        </span>
                    </td>
                    <td class="px-4 py-3 text-slate-500">{{ $provider->last_used_at?->format('d/m/Y H:i') ?? '—' }}</td>
                    <td class="px-4 py-3 text-right whitespace-nowrap">
                        <a href="{{ route('domain-providers.domains', $provider) }}" class="text-brand-600 hover:underline">Lihat domain</a>
                        <span class="mx-1 text-slate-300">|</span>
                        <a href="{{ route('domain-providers.edit', $provider) }}" class="text-brand-600 hover:underline">Ubah</a>
                        <span class="mx-1 text-slate-300">|</span>
                        <form method="POST" action="{{ route('domain-providers.toggle', $provider) }}" class="inline">
                            @csrf
                            @method('PATCH')
                            <button class="text-brand-600 hover:underline">{{ $provider->is_active ? 'Nonaktifkan' : 'Aktifkan' }}</button>
                        </form>
                        <span class="mx-1 text-slate-300">|</span>
                        <form method="POST" action="{{ route('domain-providers.destroy', $provider) }}" class="inline" onsubmit="return confirm('Hapus provider ini?')">
                            @csrf
                            @method('DELETE')
                            <button class="text-red-600 hover:underline">Hapus</button>
                        </form>
                    </td>
                </tr>
            @empty
                <tr><td colspan="6" class="px-4 py-8 text-center text-slate-500">Belum ada provider terdaftar.</td></tr>
            @endforelse
        </tbody>
    </table>
</div>
<div class="mt-4">{{ $providers->links() }}</div>
@endsection
