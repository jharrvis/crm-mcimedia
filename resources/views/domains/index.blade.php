@extends('layouts.app')

@section('title', 'Domain')

@section('content')
@php
    // Link header tabel: klik = toggle asc/desc untuk kolom itu, filter lain
    // ikut dibawa agar pengurutan tidak menghapus pencarian/filter aktif.
    $sortLink = fn (string $column) => request()->fullUrlWithQuery([
        'sort' => $column,
        'dir' => $sort === $column && $dir === 'asc' ? 'desc' : 'asc',
    ]);
    $sortLabel = fn (string $column) => match (true) {
        $sort !== $column => '↕',
        $dir === 'asc' => '↑',
        default => '↓',
    };
@endphp

<x-page-header title="Domain" icon="globe"
               subtitle="Seluruh domain MCI Media beserta klien pemilik, provider, dan tanggal kedaluwarsa." />

<x-card>
    <form method="GET" action="{{ route('domains.index') }}" class="mb-4 grid gap-2 sm:grid-cols-2 lg:grid-cols-4">
        <input name="q" value="{{ request('q') }}" placeholder="Cari nama domain…"
               class="w-full rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm placeholder:text-slate-400 focus:border-brand-500 focus:outline-none focus:ring-2 focus:ring-brand-100 dark:border-slate-700 dark:bg-slate-800 dark:text-slate-100 dark:focus:border-brand-400 dark:focus:border-brand-900">
        <select name="provider"
                class="w-full rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm focus:border-brand-500 focus:outline-none focus:ring-2 focus:ring-brand-100 dark:border-slate-700 dark:bg-slate-800 dark:text-slate-100">
            <option value="">Semua provider</option>
            <option value="none" @selected(request('provider') === 'none')>Tanpa provider</option>
            @foreach ($providers as $provider)
                <option value="{{ $provider->id }}" @selected((int) request('provider') === $provider->id)>{{ $provider->name }}</option>
            @endforeach
        </select>
        <select name="client_id"
                class="w-full rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm focus:border-brand-500 focus:outline-none focus:ring-2 focus:ring-brand-100 dark:border-slate-700 dark:bg-slate-800 dark:text-slate-100">
            <option value="">Semua klien</option>
            @foreach ($clients as $client)
                <option value="{{ $client->id }}" @selected((int) request('client_id') === $client->id)>{{ $client->name }}</option>
            @endforeach
        </select>
        <x-btn type="submit" variant="dark" icon="search">Terapkan</x-btn>
    </form>

    @if (request()->hasAny(['q', 'provider', 'client_id']))
        <div class="mb-4 text-sm">
            <a href="{{ route('domains.index') }}" class="font-semibold text-brand-600 hover:underline">Reset filter</a>
        </div>
    @endif

    <x-table>
        <thead><tr>
            <th>
                <a href="{{ $sortLink('name') }}" class="inline-flex items-center gap-1">
                    Domain <span class="text-slate-400" aria-hidden="true">{{ $sortLabel('name') }}</span>
                </a>
            </th>
            <th>
                <a href="{{ $sortLink('client') }}" class="inline-flex items-center gap-1">
                    Klien <span class="text-slate-400" aria-hidden="true">{{ $sortLabel('client') }}</span>
                </a>
            </th>
            <th>Provider</th>
            <th>
                <a href="{{ $sortLink('expired') }}" class="inline-flex items-center gap-1">
                    Berakhir <span class="text-slate-400" aria-hidden="true">{{ $sortLabel('expired') }}</span>
                </a>
            </th>
            <th>Status</th>
            <th class="text-right">Aksi</th>
        </tr></thead>
        <tbody>
            @forelse ($domains as $domain)
                @php
                    // Satu kelas background per baris: status kedaluwarsa menang
                    // atas subdomain (pola services.index).
                    $days = $domain->daysUntilEnd();
                    $overdue = $domain->isOverdue();
                    $expiring = ! $overdue && $days !== null && $days <= 30;
                    $rowClass = match (true) {
                        $overdue => 'bg-red-50 dark:bg-red-950/40',
                        $expiring => 'bg-amber-50 dark:bg-amber-950/30',
                        default => '',
                    };
                @endphp
                <tr class="{{ $rowClass }}">
                    <td>
                        <a href="{{ route('services.show', $domain) }}"
                           class="font-semibold text-slate-900 hover:text-brand-600 dark:text-white">{{ $domain->name }}</a>
                        @if ($domain->parent_id)
                            <p class="text-xs text-slate-400">Subdomain dari {{ $domain->parent?->name ?? '—' }}</p>
                        @endif
                    </td>
                    <td>
                        @if ($domain->client)
                            <a href="{{ route('clients.show', $domain->client) }}" class="hover:text-brand-600">{{ $domain->client->name }}</a>
                        @else
                            <span class="text-slate-400">—</span>
                        @endif
                    </td>
                    <td>
                        @if ($domain->domainProvider)
                            <x-badge variant="slate">{{ $domain->domainProvider->name }}</x-badge>
                        @else
                            <span class="text-slate-400">—</span>
                        @endif
                    </td>
                    <td class="whitespace-nowrap">
                        {{ tgl_id($domain->end_date) }}
                        @if ($overdue)
                            <x-badge variant="danger" :dot="true" class="ml-1">{{ abs($days) }} hari lewat</x-badge>
                        @elseif ($expiring)
                            <x-badge variant="warning" :dot="true" class="ml-1">{{ $days }} hari</x-badge>
                        @endif
                    </td>
                    <td>
                        <x-badge :variant="$domain->status->value === 'active' ? 'success' : 'slate'" :dot="true">
                            {{ $domain->status->label() }}
                        </x-badge>
                    </td>
                    <td class="whitespace-nowrap text-right">
                        <a href="{{ route('services.show', $domain) }}" class="text-sm font-semibold text-brand-600 hover:underline">Detail</a>
                        <span class="mx-1 text-slate-300">|</span>
                        <a href="{{ route('services.edit', $domain) }}" class="text-sm font-semibold text-brand-600 hover:underline">Ubah</a>
                    </td>
                </tr>
            @empty
                <tr><td colspan="6">
                    <x-empty-state title="Belum ada domain" icon="globe"
                                   description="Belum ada domain yang cocok dengan filter." />
                </td></tr>
            @endforelse
        </tbody>
    </x-table>

    @if ($domains->hasPages())
        <div class="mt-4">{{ $domains->links() }}</div>
    @endif
</x-card>
@endsection
