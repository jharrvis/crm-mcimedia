@extends('layouts.app')

@section('title', 'Provider Domain')

@section('content')
<x-page-header title="Provider Domain" subtitle="Registry penyedia domain/hosting — kredensial tersimpan terenkripsi." icon="globe">
    <x-btn :href="route('domain-providers.create')" icon="plus">Tambah provider</x-btn>
</x-page-header>

<x-card>
    <form method="GET" action="{{ route('domain-providers.index') }}" class="mb-4 grid gap-2 sm:grid-cols-3">
        <input name="q" value="{{ request('q') }}" placeholder="Cari nama / driver…"
               class="w-full rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm placeholder:text-slate-400 focus:border-brand-500 focus:outline-none focus:ring-2 focus:ring-brand-100 dark:border-slate-700 dark:bg-slate-800 dark:text-slate-100 dark:focus:border-brand-400 dark:focus:ring-brand-900">
        <select name="status"
                class="w-full rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm focus:border-brand-500 focus:outline-none focus:ring-2 focus:ring-brand-100 dark:border-slate-700 dark:bg-slate-800 dark:text-slate-100">
            <option value="all" @selected($statusFilter === 'all')>Semua status</option>
            <option value="active" @selected($statusFilter === 'active')>Aktif</option>
            <option value="inactive" @selected($statusFilter === 'inactive')>Nonaktif</option>
        </select>
        <x-btn type="submit" variant="dark" icon="search">Filter</x-btn>
    </form>

    <x-table>
        <thead><tr>
            <th>Nama</th>
            <th>Driver</th>
            <th>Catatan</th>
            <th>Status</th>
            <th>Terakhir dipakai</th>
            <th class="text-right">Aksi</th>
        </tr></thead>
        <tbody>
            @forelse ($providers as $provider)
                <tr>
                    <td class="font-semibold">{{ $provider->name }}</td>
                    <td><x-badge variant="slate">{{ $drivers[$provider->driver] ?? $provider->driver }}</x-badge></td>
                    <td class="text-slate-400">{{ $provider->notes ?? '—' }}</td>
                    <td>
                        <x-badge :variant="$provider->is_active ? 'success' : 'slate'" :dot="true">
                            {{ $provider->is_active ? 'Aktif' : 'Nonaktif' }}
                        </x-badge>
                    </td>
                    <td class="text-slate-400">{{ $provider->last_used_at?->format('d/m/Y H:i') ?? '—' }}</td>
                    <td class="whitespace-nowrap text-right">
                        <a href="{{ route('domain-providers.domains', $provider) }}" class="text-sm font-semibold text-brand-600 hover:underline">Lihat domain</a>
                        <span class="mx-1 text-slate-300">|</span>
                        <a href="{{ route('domain-providers.edit', $provider) }}" class="text-sm font-semibold text-brand-600 hover:underline">Ubah</a>
                        <span class="mx-1 text-slate-300">|</span>
                        <form method="POST" action="{{ route('domain-providers.toggle', $provider) }}" class="inline">
                            @csrf
                            @method('PATCH')
                            <button type="submit" class="text-sm font-semibold text-brand-600 hover:underline">{{ $provider->is_active ? 'Nonaktifkan' : 'Aktifkan' }}</button>
                        </form>
                        <span class="mx-1 text-slate-300">|</span>
                        <form method="POST" action="{{ route('domain-providers.destroy', $provider) }}" class="inline" onsubmit="return confirm('Hapus provider ini?')">
                            @csrf
                            @method('DELETE')
                            <button type="submit" class="text-sm font-semibold text-rose-600 hover:underline">Hapus</button>
                        </form>
                    </td>
                </tr>
            @empty
                <tr><td colspan="6">
                    <x-empty-state title="Belum ada provider terdaftar" icon="globe"
                                   description="Daftarkan provider untuk mengimpor domain dan mengelola auto-renew." />
                </td></tr>
            @endforelse
        </tbody>
    </x-table>

    @if ($providers->hasPages())
        <div class="mt-4">{{ $providers->links() }}</div>
    @endif
</x-card>
@endsection