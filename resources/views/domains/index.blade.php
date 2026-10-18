@extends('layouts.app')

@section('title', 'Domain')

@section('content')
<x-page-header title="Domain" subtitle="Daftar seluruh domain beserta mapping ke klien.">
    <x-btn :href="route('services.create', ['type' => 'domain'])" icon="plus">Tambah domain</x-btn>
</x-page-header>

{{-- Statistik ringkas --}}
<div class="mb-4 grid gap-3 sm:grid-cols-4">
    <x-card class="p-4">
        <p class="text-xs uppercase text-slate-500">Total domain</p>
        <p class="text-2xl font-bold">{{ $stats['total'] }}</p>
    </x-card>
    <x-card class="p-4">
        <p class="text-xs uppercase text-slate-500">Belum ada klien</p>
        <p class="text-2xl font-bold {{ $stats['unmapped'] > 0 ? 'text-amber-600' : '' }}">{{ $stats['unmapped'] }}</p>
    </x-card>
    <x-card class="p-4">
        <p class="text-xs uppercase text-slate-500">Expired &lt; 30 hari</p>
        <p class="text-2xl font-bold {{ $stats['expiring'] > 0 ? 'text-amber-600' : '' }}">{{ $stats['expiring'] }}</p>
    </x-card>
    <x-card class="p-4">
        <p class="text-xs uppercase text-slate-500">Sudah expired</p>
        <p class="text-2xl font-bold {{ $stats['expired'] > 0 ? 'text-red-600' : '' }}">{{ $stats['expired'] }}</p>
    </x-card>
</div>

<x-card>
    <form method="GET" action="{{ route('domains.index') }}" class="mb-4 grid gap-2 sm:grid-cols-2 lg:grid-cols-5">
        <input name="q" value="{{ request('q') }}" placeholder="Cari nama domain…"
               class="w-full rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm placeholder:text-slate-400 focus:border-brand-500 focus:outline-none focus:ring-2 focus:ring-brand-100 dark:border-slate-700 dark:bg-slate-800 dark:text-slate-100">
        <select name="client_id"
                class="w-full rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm dark:border-slate-700 dark:bg-slate-800 dark:text-slate-100">
            <option value="">Semua klien</option>
            @foreach ($clients as $c)
                <option value="{{ $c->id }}" @selected(request('client_id') == $c->id)>{{ $c->name }}</option>
            @endforeach
        </select>
        <select name="status"
                class="w-full rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm dark:border-slate-700 dark:bg-slate-800 dark:text-slate-100">
            <option value="all" @selected(request('status', 'all') === 'all')>Semua status</option>
            @foreach ($statuses as $s)
                <option value="{{ $s->value }}" @selected(request('status') === $s->value)>{{ $s->label() }}</option>
            @endforeach
        </select>
        <label class="flex items-center gap-2 text-sm">
            <input type="checkbox" name="unmapped" value="1" @checked(request('unmapped') === '1')
                   class="rounded border-slate-300">
            Belum ada klien
        </label>
        <x-btn type="submit" variant="dark" icon="search">Filter</x-btn>
    </form>

    <x-table>
        <thead><tr>
            <th>
                <a href="{{ request()->fullUrlWithQuery(['sort' => 'name', 'dir' => request('sort') === 'name' && request('dir') === 'asc' ? 'desc' : 'asc']) }}"
                   class="hover:underline">Nama domain</a>
            </th>
            <th>Klien</th>
            <th>
                <a href="{{ request()->fullUrlWithQuery(['sort' => 'end_date', 'dir' => request('sort') === 'end_date' && request('dir') === 'asc' ? 'desc' : 'asc']) }}"
                   class="hover:underline">Expired</a>
            </th>
            <th>
                <a href="{{ request()->fullUrlWithQuery(['sort' => 'status', 'dir' => request('sort') === 'status' && request('dir') === 'asc' ? 'desc' : 'asc']) }}"
                   class="hover:underline">Status</a>
            </th>
            <th class="text-right">Aksi</th>
        </tr></thead>
        <tbody>
            @forelse ($domains as $domain)
                @php
                    $days = $domain->daysUntilEnd();
                    $isExpired = $domain->end_date && $domain->end_date->isPast();
                    $expiringSoon = ! $isExpired && $days !== null && $days <= 30;
                    $rowClass = $isExpired ? 'bg-red-50 dark:bg-red-950/20' : ($expiringSoon ? 'bg-amber-50 dark:bg-amber-950/20' : '');
                @endphp
                <tr class="{{ $rowClass }}">
                    <td class="font-medium">
                        {{ $domain->name ?: $domain->reference }}
                        @if ($isExpired)
                            <x-badge variant="danger" class="ml-2">Expired</x-badge>
                        @elseif ($expiringSoon)
                            <x-badge variant="warning" class="ml-2">{{ $days }} hari lagi</x-badge>
                        @endif
                    </td>
                    <td>
                        @if ($domain->client)
                            <a href="{{ route('clients.show', $domain->client) }}" class="text-brand-600 hover:underline">
                                {{ $domain->client->name }}
                            </a>
                        @else
                            <span class="text-amber-600 font-medium">— Belum di-mapping —</span>
                            <a href="{{ route('services.edit', $domain) }}" class="ml-1 text-xs text-brand-600 hover:underline">Mapping</a>
                        @endif
                    </td>
                    <td>{{ $domain->end_date ? $domain->end_date->format('d/m/Y') : '—' }}</td>
                    <td><x-badge>{{ $domain->status->label() }}</x-badge></td>
                    <td class="text-right">
                        <a href="{{ route('services.show', $domain) }}" class="text-brand-600 hover:underline text-sm">Detail</a>
                    </td>
                </tr>
            @empty
                <tr><td colspan="5" class="text-center text-slate-500 py-8">Tidak ada domain ditemukan.</td></tr>
            @endforelse
        </tbody>
    </x-table>

    <div class="mt-4">{{ $domains->links() }}</div>
</x-card>
@endsection
