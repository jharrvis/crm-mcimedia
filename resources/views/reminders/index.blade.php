@extends('layouts.app')

@section('title', 'Pengingat Jatuh Tempo')

@section('content')
<x-page-header title="Pengingat Jatuh Tempo"
               subtitle="Layanan aktif dengan tanggal berakhir ≤ {{ $days }} hari ke depan, plus yang sudah lewat. Pengingat harian juga berjalan via scheduler (crm:services-expiring)."
               icon="bell" />

@if ($overdue->isNotEmpty())
    <x-card class="mb-6 border-red-200 dark:border-red-900">
        <x-slot:header>
            <h2 class="font-bold text-red-700 dark:text-red-300">Sudah lewat jatuh tempo ({{ $overdue->count() }})</h2>
        </x-slot:header>
        <x-table>
            <thead><tr>
                <th>Klien</th>
                <th>Layanan</th>
                <th>Jenis</th>
                <th>Berakhir</th>
                <th>Terlambat</th>
                <th class="text-right">Harga</th>
                <th class="text-right">Aksi</th>
            </tr></thead>
            <tbody>
                @foreach ($overdue as $s)
                    <tr>
                        <td><a href="{{ route('clients.show', $s->client) }}" class="hover:text-brand-600">{{ $s->client?->name ?? '—' }}</a></td>
                        <td><a href="{{ route('services.show', $s) }}" class="font-semibold text-brand-600 hover:underline">{{ $s->name }}</a></td>
                        <td>{{ $s->type->label() }}</td>
                        <td>{{ tgl_id($s->end_date) }}</td>
                        <td class="font-semibold text-red-600">{{ abs($s->daysUntilEnd()) }} hari</td>
                        <td class="text-right">{{ rupiah($s->price) }}</td>
                        <td class="whitespace-nowrap text-right">@include('reminders._actions', ['s' => $s])</td>
                    </tr>
                @endforeach
            </tbody>
        </x-table>
    </x-card>
@endif

<x-card>
    <x-slot:header>
        <h2 class="font-bold text-amber-700 dark:text-amber-300">Jatuh tempo ≤ {{ $days }} hari ({{ $expiring->count() }})</h2>
    </x-slot:header>
    @if ($expiring->isEmpty())
        <x-empty-state title="Tidak ada layanan yang jatuh tempo" icon="bell"
                       :description="'Tidak ada layanan yang jatuh tempo dalam ' . $days . ' hari.'" />
    @else
        <x-table>
            <thead><tr>
                <th>Klien</th>
                <th>Layanan</th>
                <th>Jenis</th>
                <th>Berakhir</th>
                <th>Sisa</th>
                <th class="text-right">Harga</th>
                <th class="text-right">Aksi</th>
            </tr></thead>
            <tbody>
                @foreach ($expiring as $s)
                    <tr>
                        <td><a href="{{ route('clients.show', $s->client) }}" class="hover:text-brand-600">{{ $s->client?->name ?? '—' }}</a></td>
                        <td><a href="{{ route('services.show', $s) }}" class="font-semibold text-brand-600 hover:underline">{{ $s->name }}</a></td>
                        <td>{{ $s->type->label() }}</td>
                        <td>{{ tgl_id($s->end_date) }}</td>
                        <td class="font-semibold text-amber-600">{{ $s->daysUntilEnd() }} hari</td>
                        <td class="text-right">{{ rupiah($s->price) }}</td>
                        <td class="whitespace-nowrap text-right">@include('reminders._actions', ['s' => $s])</td>
                    </tr>
                @endforeach
            </tbody>
        </x-table>
    @endif
</x-card>
@endsection
