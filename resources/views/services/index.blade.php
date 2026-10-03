@extends('layouts.app')

@section('title', 'Layanan')

@section('content')
<div class="mb-4 flex flex-wrap items-center gap-2">
    <a href="{{ route('services.create') }}" class="rounded-lg bg-indigo-600 px-4 py-2 text-sm font-semibold text-white hover:bg-indigo-700">Tambah layanan</a>
</div>

<form method="GET" action="{{ route('services.index') }}" class="mb-4 grid gap-2 rounded-xl border border-slate-200 bg-white p-4 sm:grid-cols-5 dark:border-slate-800 dark:bg-slate-900">
    <input name="q" value="{{ request('q') }}" placeholder="Cari nama / referensi…" class="rounded-lg border border-slate-300 px-3 py-2 text-sm dark:border-slate-700 dark:bg-slate-800">
    <x-client-select
        :clients="$clients"
        :selected="request('client_id')"
        empty-label="Semua klien"
        class="rounded-lg border border-slate-300 px-3 py-2 text-sm dark:border-slate-700 dark:bg-slate-800" />
    <select name="type" class="rounded-lg border border-slate-300 px-3 py-2 text-sm dark:border-slate-700 dark:bg-slate-800">
        <option value="">Semua jenis</option>
        @foreach ($types as $t)
            <option value="{{ $t->value }}" @selected(request('type') === $t->value)>{{ $t->label() }}</option>
        @endforeach
    </select>
    <select name="status" class="rounded-lg border border-slate-300 px-3 py-2 text-sm dark:border-slate-700 dark:bg-slate-800">
        <option value="all" @selected(request('status', 'active') === 'all')>Semua status</option>
        @foreach ($statuses as $s)
            <option value="{{ $s->value }}" @selected(request('status', 'active') === $s->value)>{{ $s->label() }}</option>
        @endforeach
    </select>
    <button class="rounded-lg bg-slate-800 px-4 py-2 text-sm font-semibold text-white hover:bg-slate-700 dark:bg-slate-700">Filter</button>
</form>

<div class="overflow-x-auto rounded-xl border border-slate-200 bg-white dark:border-slate-800 dark:bg-slate-900">
    <table class="w-full text-sm">
        <thead>
            <tr class="text-left text-xs uppercase text-slate-500">
                <th class="px-4 py-3">Klien</th>
                <th class="px-4 py-3">Layanan</th>
                <th class="px-4 py-3">Jenis</th>
                <th class="px-4 py-3">Berakhir</th>
                <th class="px-4 py-3 text-right">Harga</th>
                <th class="px-4 py-3">Status</th>
                <th class="px-4 py-3 text-right">Aksi</th>
            </tr>
        </thead>
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
                <tr class="border-t border-slate-100 dark:border-slate-800 {{ $rowClass }}">
                    <td class="px-4 py-3">
                        <a href="{{ route('clients.show', $service->client) }}" class="hover:text-indigo-600">{{ $service->client?->name ?? '—' }}</a>
                    </td>
                    <td class="px-4 py-3 {{ $isSub ? 'pl-10' : '' }}">
                        @if ($isSub)
                            <span class="mr-1 text-slate-400" aria-hidden="true">↳</span>
                        @endif
                        <a href="{{ route('services.show', $service) }}" class="font-medium text-indigo-600 hover:underline">{{ $service->name }}</a>
                        @if ($service->reference)<p class="text-xs text-slate-500">{{ $service->reference }}</p>@endif
                        @if ($service->parent)
                            <p class="text-xs text-slate-400">Subdomain dari <a href="{{ route('services.show', $service->parent) }}" class="hover:underline">{{ $service->parent->name }}</a></p>
                        @endif
                    </td>
                    <td class="px-4 py-3">{{ $service->type->label() }}</td>
                    <td class="px-4 py-3">
                        {{ tgl_id($service->end_date) }}
                        @if ($overdue)
                            <span class="ml-1 rounded-full bg-red-100 px-2 py-0.5 text-xs font-semibold text-red-700 dark:bg-red-900 dark:text-red-200">{{ abs($days) }} hari lewat</span>
                        @elseif ($expiring)
                            <span class="ml-1 rounded-full bg-amber-100 px-2 py-0.5 text-xs font-semibold text-amber-700 dark:bg-amber-900 dark:text-amber-200">{{ $days }} hari</span>
                        @endif
                    </td>
                    <td class="px-4 py-3 text-right">{{ rupiah($service->price) }}</td>
                    <td class="px-4 py-3">
                        <span class="rounded-full px-2 py-0.5 text-xs font-medium {{ $service->status->value === 'active' ? 'bg-green-100 text-green-700 dark:bg-green-900 dark:text-green-200' : 'bg-slate-200 text-slate-600 dark:bg-slate-700 dark:text-slate-300' }}">{{ $service->status->label() }}</span>
                    </td>
                    <td class="px-4 py-3 text-right whitespace-nowrap">
                        <a href="{{ route('services.show', $service) }}" class="text-indigo-600 hover:underline">Detail</a>
                        <span class="mx-1 text-slate-300">|</span>
                        <a href="{{ route('services.edit', $service) }}" class="text-indigo-600 hover:underline">Ubah</a>
                        <span class="mx-1 text-slate-300">|</span>
                        <form method="POST" action="{{ route('services.destroy', $service) }}" class="inline" onsubmit="return confirm('Hapus layanan ini?')">
                            @csrf
                            @method('DELETE')
                            <button class="text-red-600 hover:underline">Hapus</button>
                        </form>
                    </td>
                </tr>
            @empty
                <tr><td colspan="7" class="px-4 py-8 text-center text-slate-500">Belum ada layanan.</td></tr>
            @endforelse
        </tbody>
    </table>
</div>
<div class="mt-4">{{ $services->links() }}</div>
@endsection
