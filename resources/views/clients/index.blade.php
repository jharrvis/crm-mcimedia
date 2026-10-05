@extends('layouts.app')

@section('title', 'Klien')

@section('content')
<div class="mb-4 flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
    <form method="GET" action="{{ route('clients.index') }}" class="flex gap-2">
        <input name="q" value="{{ request('q') }}" placeholder="Cari nama usaha / kontak…"
               class="w-64 rounded-lg border border-slate-300 px-3 py-2 text-sm dark:border-slate-700 dark:bg-slate-800">
        <button class="rounded-lg border border-slate-300 px-4 py-2 text-sm font-medium hover:bg-slate-50 dark:border-slate-700 dark:hover:bg-slate-800">Cari</button>
    </form>
    <a href="{{ route('clients.create') }}"
       class="rounded-lg bg-brand-600 px-4 py-2 text-sm font-semibold text-white hover:bg-brand-700">Tambah klien</a>
</div>

<div class="overflow-x-auto rounded-xl border border-slate-200 bg-white dark:border-slate-800 dark:bg-slate-900">
    <table class="w-full text-sm">
        <thead>
            <tr class="text-left text-xs uppercase text-slate-500">
                <th class="px-4 py-3">Nama usaha</th>
                <th class="px-4 py-3">Kontak utama</th>
                <th class="px-4 py-3">Email / WA</th>
                <th class="px-4 py-3">Status</th>
                <th class="px-4 py-3 text-right">Aksi</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($clients as $client)
                <tr class="border-t border-slate-100 dark:border-slate-800">
                    <td class="px-4 py-3">
                        <a href="{{ route('clients.show', $client) }}" class="font-medium text-brand-600 hover:underline">{{ $client->name }}</a>
                    </td>
                    <td class="px-4 py-3">{{ $client->contact_name ?? '—' }}</td>
                    <td class="px-4 py-3 text-slate-500">
                        {{ $client->email ?? '—' }}<br class="sm:hidden">
                        <span class="text-xs">{{ $client->whatsapp ?? '' }}</span>
                    </td>
                    <td class="px-4 py-3">
                        @if ($client->is_active)
                            <span class="rounded-full bg-green-100 px-2 py-0.5 text-xs font-medium text-green-700 dark:bg-green-950 dark:text-green-300">Aktif</span>
                        @else
                            <span class="rounded-full bg-slate-200 px-2 py-0.5 text-xs font-medium text-slate-600 dark:bg-slate-800 dark:text-slate-300">Arsip</span>
                        @endif
                    </td>
                    <td class="px-4 py-3 text-right">
                        <a href="{{ route('clients.edit', $client) }}" class="text-sm text-brand-600 hover:underline">Ubah</a>
                        <form method="POST" action="{{ route('clients.destroy', $client) }}" class="inline"
                              onsubmit="return confirm('Hapus klien {{ $client->name }} beserta layanannya?')">
                            @csrf
                            @method('DELETE')
                            <button class="ml-2 text-sm text-red-600 hover:underline">Hapus</button>
                        </form>
                    </td>
                </tr>
            @empty
                <tr><td colspan="5" class="px-4 py-8 text-center text-slate-500">Belum ada klien.</td></tr>
            @endforelse
        </tbody>
    </table>
</div>

<div class="mt-4">{{ $clients->links() }}</div>
@endsection
