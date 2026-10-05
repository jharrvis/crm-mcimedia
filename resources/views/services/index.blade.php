@extends('layouts.app')

@section('title', 'Layanan')

@section('content')
<x-page-header title="Layanan" subtitle="Hosting, domain, dan maintenance yang sedang dikelola MCI Media.">
    <x-btn :href="route('services.create')" icon="plus">Tambah layanan</x-btn>
</x-page-header>

<x-card>
    <form method="GET" action="{{ route('services.index') }}" class="mb-4 grid gap-2 sm:grid-cols-2 lg:grid-cols-5">
        <input name="q" value="{{ request('q') }}" placeholder="Cari nama / referensi…"
               class="w-full rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm placeholder:text-slate-400 focus:border-brand-500 focus:outline-none focus:ring-2 focus:ring-brand-100 dark:border-slate-700 dark:bg-slate-800 dark:text-slate-100 dark:focus:border-brand-400 dark:focus:ring-brand-900">
        <select name="client_id"
                class="w-full rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm focus:border-brand-500 focus:outline-none focus:ring-2 focus:ring-brand-100 dark:border-slate-700 dark:bg-slate-800 dark:text-slate-100">
            <option value="">Semua klien</option>
            @foreach ($clients as $c)
                <option value="{{ $c->id }}" @selected(request('client_id') == $c->id)>{{ $c->name }}</option>
            @endforeach
        </select>
        <select name="type"
                class="w-full rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm focus:border-brand-500 focus:outline-none focus:ring-2 focus:ring-brand-100 dark:border-slate-700 dark:bg-slate-800 dark:text-slate-100">
            <option value="">Semua jenis</option>
            @foreach ($types as $t)
                <option value="{{ $t->value }}" @selected(request('type') === $t->value)>{{ $t->label() }}</option>
            @endforeach
        </select>
        <select name="status"
                class="w-full rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm focus:border-brand-500 focus:outline-none focus:ring-2 focus:ring-brand-100 dark:border-slate-700 dark:bg-slate-800 dark:text-slate-100">
            <option value="all" @selected(request('status', 'active') === 'all')>Semua status</option>
            @foreach ($statuses as $s)
                <option value="{{ $s->value }}" @selected(request('status', 'active') === $s->value)>{{ $s->label() }}</option>
            @endforeach
        </select>
        <x-btn type="submit" variant="dark" icon="search">Filter</x-btn>
    </form>

    <x-table>
        <thead><tr>
            <th>Klien</th>
            <th>Layanan</th>
            <th>Jenis</th>
            <th>Berakhir</th>
            <th class="text-right">Harga</th>
            <th>Status</th>
            <th class="text-right">Aksi</th>
        </tr></thead>
        <tbody>
            @forelse ($services as $service)
                @php
                    $days = $service->daysUntilEnd();
                    $overdue = $service->isOverdue();
                    $expiring = ! $overdue && $days !== null && $days <= 30;
                    // F4-9: subdomain ditampilkan indentasi di bawah domain induknya.
                    $isSub = $service->isChild();
                    // Hanya SATU kelas background per baris. Kalau subdomain juga
                    // diberi `bg-slate-*` terpisah, class mana yang menang ditentukan
                    // urutan CSS Tailwind — bukan urutan atribut — sehingga penanda
                    // "lewat"/"segera berakhir" bisa hilang diam-diam. Karena itu
                    // status lebih penting dan tetap menang; subdomain memakai latar
                    // abu-abu hanya ketika tidak ada penanda status.
                    $rowClass = match (true) {
                        $overdue => 'bg-red-50 dark:bg-red-950/40',
                        $expiring => 'bg-amber-50 dark:bg-amber-950/30',
                        $isSub => 'bg-slate-50/60 dark:bg-slate-950/30',
                        default => '',
                    };
                @endphp
                <tr class="{{ $rowClass }}">
                    <td>
                        <a href="{{ route('clients.show', $service->client) }}" class="hover:text-brand-600">{{ $service->client?->name ?? '—' }}</a>
                    </td>
                    <td @class(['pl-8' => $isSub])>
                        @if ($isSub)
                            <span class="mr-1 text-slate-400" aria-hidden="true">↳</span>
                        @endif
                        <a href="{{ route('services.show', $service) }}" class="font-semibold text-slate-900 hover:text-brand-600 dark:text-white">{{ $service->name }}</a>
                        @if ($service->reference)<p class="text-xs text-slate-400">{{ $service->reference }}</p>@endif
                        @if ($service->parent)
                            <p class="text-xs text-slate-400">Subdomain dari <a href="{{ route('services.show', $service->parent) }}" class="hover:underline">{{ $service->parent->name }}</a></p>
                        @endif
                    </td>
                    <td>{{ $service->type->label() }}</td>
                    <td>
                        {{ tgl_id($service->end_date) }}
                        @if ($overdue)
                            <x-badge variant="danger" :dot="true" class="ml-1">{{ abs($days) }} hari lewat</x-badge>
                        @elseif ($expiring)
                            <x-badge variant="warning" :dot="true" class="ml-1">{{ $days }} hari</x-badge>
                        @endif
                    </td>
                    <td class="text-right">{{ rupiah($service->price) }}</td>
                    <td>
                        <x-badge :variant="$service->status->value === 'active' ? 'success' : 'slate'" :dot="true">
                            {{ $service->status->label() }}
                        </x-badge>
                    </td>
                    <td class="whitespace-nowrap text-right">
                        <a href="{{ route('services.show', $service) }}" class="text-sm font-semibold text-brand-600 hover:underline">Detail</a>
                        <span class="mx-1 text-slate-300">|</span>
                        <a href="{{ route('services.edit', $service) }}" class="text-sm font-semibold text-brand-600 hover:underline">Ubah</a>
                        <span class="mx-1 text-slate-300">|</span>
                        <form method="POST" action="{{ route('services.destroy', $service) }}" class="inline" onsubmit="return confirm('Hapus layanan ini?')">
                            @csrf
                            @method('DELETE')
                            <button type="submit" class="text-sm font-semibold text-rose-600 hover:underline">Hapus</button>
                        </form>
                    </td>
                </tr>
            @empty
                <tr><td colspan="7">
                    <x-empty-state title="Belum ada layanan" icon="wrench"
                                   description="Belum ada layanan yang cocok dengan filter.">
                        <x-slot:action>
                            <x-btn :href="route('services.create')" icon="plus">Tambah layanan</x-btn>
                        </x-slot:action>
                    </x-empty-state>
                </td></tr>
            @endforelse
        </tbody>
    </x-table>

    @if ($services->hasPages())
        <div class="mt-4">{{ $services->links() }}</div>
    @endif
</x-card>
@endsection