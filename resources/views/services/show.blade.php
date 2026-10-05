@extends('layouts.app')

@section('title', $service->name)

@section('content')
<x-page-header :title="$service->name" back="{{ route('services.index') }}" backLabel="Kembali ke daftar" icon="wrench">
    <x-btn :href="route('services.edit', $service)" icon="wrench">Ubah</x-btn>
    <form method="POST" action="{{ route('services.destroy', $service) }}" onsubmit="return confirm('Hapus layanan ini?')">
        @csrf
        @method('DELETE')
        <x-btn type="submit" variant="danger">Hapus</x-btn>
    </form>
</x-page-header>

<div class="max-w-3xl">
    <x-card>
        <dl class="grid gap-x-6 gap-y-4 text-sm sm:grid-cols-2">
            <div><dt class="text-xs uppercase text-slate-400">Klien</dt><dd class="font-semibold"><a href="{{ route('clients.show', $service->client) }}" class="text-brand-600 hover:underline">{{ $service->client?->name ?? '—' }}</a></dd></div>
            <div><dt class="text-xs uppercase text-slate-400">Jenis</dt><dd class="font-semibold">{{ $service->type->label() }}</dd></div>
            <div><dt class="text-xs uppercase text-slate-400">Nama layanan</dt><dd class="font-semibold">{{ $service->name }}</dd></div>
            <div>
                <dt class="text-xs uppercase text-slate-400">Domain induk</dt>
                <dd class="font-semibold">
                    @if ($service->parent)
                        <a href="{{ route('services.show', $service->parent) }}" class="text-brand-600 hover:underline">{{ $service->parent->name }}</a>
                        <span class="text-xs font-normal text-slate-400">(subdomain)</span>
                    @else
                        —
                    @endif
                </dd>
            </div>
            <div><dt class="text-xs uppercase text-slate-400">Domain / server terkait</dt><dd class="font-semibold">{{ $service->reference ?? '—' }}</dd></div>
            <div><dt class="text-xs uppercase text-slate-400">Tanggal mulai</dt><dd class="font-semibold">{{ tgl_id($service->start_date) }}</dd></div>
            <div>
                <dt class="text-xs uppercase text-slate-400">Tanggal berakhir</dt>
                <dd class="font-semibold">
                    {{ tgl_id($service->end_date) }}
                    @php $days = $service->daysUntilEnd(); @endphp
                    @if ($service->isOverdue())
                        <x-badge variant="danger" :dot="true" class="ml-1">{{ abs($days) }} hari lewat</x-badge>
                    @elseif ($days !== null && $days <= 30)
                        <x-badge variant="warning" :dot="true" class="ml-1">sisa {{ $days }} hari</x-badge>
                    @endif
                </dd>
            </div>
            <div><dt class="text-xs uppercase text-slate-400">Harga</dt><dd class="font-semibold">{{ rupiah($service->price) }}</dd></div>
            <div><dt class="text-xs uppercase text-slate-400">Siklus</dt><dd class="font-semibold">{{ $service->cycle->label() }}</dd></div>
            <div><dt class="text-xs uppercase text-slate-400">Status</dt>
                <dd><x-badge :variant="$service->status->value === 'active' ? 'success' : 'slate'" :dot="true">{{ $service->status->label() }}</x-badge></dd>
            </div>
            <div><dt class="text-xs uppercase text-slate-400">Pengingat otomatis</dt>
                <dd><x-badge :variant="$service->reminder_enabled ? 'success' : 'slate'" :dot="true">{{ $service->reminder_enabled ? 'Aktif' : 'Nonaktif' }}</x-badge></dd>
            </div>
            <div class="sm:col-span-2"><dt class="text-xs uppercase text-slate-400">Catatan</dt><dd class="font-medium whitespace-pre-line">{{ $service->notes ?? '—' }}</dd></div>
        </dl>
    </x-card>
</div>

@if ($service->children->isNotEmpty())
<x-card class="mt-6">
    <x-slot:header>
        <h2 class="font-bold">Subdomain ({{ $service->children->count() }})</h2>
    </x-slot:header>

    <x-table>
        <thead><tr>
            <th>Subdomain</th><th>Referensi</th><th>Berakhir</th><th>Status</th><th class="text-right">Harga</th>
        </tr></thead>
        <tbody>
            @foreach ($service->children as $child)
                <tr>
                    <td>
                        <span class="mr-1 text-slate-400" aria-hidden="true">↳</span>
                        <a href="{{ route('services.show', $child) }}" class="font-semibold text-slate-900 hover:text-brand-600 dark:text-white">{{ $child->name }}</a>
                    </td>
                    <td class="text-slate-500">{{ $child->reference ?? '—' }}</td>
                    <td>{{ tgl_id($child->end_date) }}</td>
                    <td>
                        <x-badge :variant="$child->status->value === 'active' ? 'success' : 'slate'" :dot="true">{{ $child->status->label() }}</x-badge>
                    </td>
                    <td class="text-right">{{ rupiah($child->price) }}</td>
                </tr>
            @endforeach
        </tbody>
    </x-table>
</x-card>
@endif
@endsection
