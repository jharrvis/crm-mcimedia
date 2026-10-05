@extends('layouts.app')

@section('title', 'Klien')

@section('content')
<x-page-header title="Klien" subtitle="Semua akun dan kontak yang dilayani MCI Media.">
    <x-btn :href="route('clients.create')" icon="plus">Tambah klien</x-btn>
</x-page-header>

<x-card>
    <form method="GET" action="{{ route('clients.index') }}" class="mb-4 flex gap-2">
        <input name="q" value="{{ request('q') }}" placeholder="Cari nama usaha / kontak…"
               class="w-full max-w-xs rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm placeholder:text-slate-400 focus:border-brand-500 focus:outline-none focus:ring-2 focus:ring-brand-100 dark:border-slate-700 dark:bg-slate-800 dark:focus:border-brand-400 dark:focus:ring-brand-900">
        <x-btn type="submit" variant="outline" icon="search">Cari</x-btn>
    </form>

    <x-table>
        <thead><tr>
            <th>Nama usaha</th>
            <th>Kontak utama</th>
            <th>Email / WA</th>
            <th>Status</th>
            <th class="text-right">Aksi</th>
        </tr></thead>
        <tbody>
            @forelse ($clients as $client)
                <tr>
                    <td>
                        <a href="{{ route('clients.show', $client) }}" class="font-semibold text-slate-900 hover:text-brand-600 dark:text-white">{{ $client->name }}</a>
                    </td>
                    <td>{{ $client->contact_name ?? '—' }}</td>
                    <td class="text-slate-500">
                        {{ $client->email ?? '—' }}<br class="sm:hidden">
                        <span class="text-xs">{{ $client->whatsapp ?? '' }}</span>
                    </td>
                    <td>
                        <x-badge :variant="$client->is_active ? 'success' : 'slate'" :dot="true">
                            {{ $client->is_active ? 'Aktif' : 'Arsip' }}
                        </x-badge>
                    </td>
                    <td class="text-right">
                        <a href="{{ route('clients.edit', $client) }}" class="text-sm font-semibold text-brand-600 hover:underline">Ubah</a>
                        <form method="POST" action="{{ route('clients.destroy', $client) }}" class="inline"
                              onsubmit="return confirm('Hapus klien {{ $client->name }} beserta layanannya?')">
                            @csrf
                            @method('DELETE')
                            <button type="submit" class="ml-2 text-sm font-semibold text-rose-600 hover:underline">Hapus</button>
                        </form>
                    </td>
                </tr>
            @empty
                <tr><td colspan="5">
                    <x-empty-state title="Belum ada klien" icon="users">
                        Tambahkan klien pertama untuk mulai mencatat layanan, invoice, dan project.
                        <x-slot:action>
                            <x-btn :href="route('clients.create')" icon="plus">Tambah klien</x-btn>
                        </x-slot:action>
                    </x-empty-state>
                </td></tr>
            @endforelse
        </tbody>
    </x-table>

    @if ($clients->hasPages())
        <div class="mt-4">{{ $clients->links() }}</div>
    @endif
</x-card>
@endsection
