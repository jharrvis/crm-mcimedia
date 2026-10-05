@extends('layouts.app')

@section('title', 'Pengingat Jatuh Tempo')

@section('content')
<p class="mb-4 text-sm text-slate-500">Layanan aktif dengan tanggal berakhir ≤ {{ $days }} hari ke depan, plus yang sudah lewat. Pengingat harian juga berjalan via scheduler (<code>crm:services-expiring</code>).</p>

@if ($overdue->isNotEmpty())
    <div class="mb-6 rounded-xl border border-red-200 bg-white p-5 dark:border-red-900 dark:bg-slate-900">
        <h2 class="mb-3 font-bold text-red-700 dark:text-red-300">Sudah lewat jatuh tempo ({{ $overdue->count() }})</h2>
        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead><tr class="text-left text-xs uppercase text-slate-500">
                    <th class="py-2 pr-4">Klien</th><th class="py-2 pr-4">Layanan</th><th class="py-2 pr-4">Jenis</th><th class="py-2 pr-4">Berakhir</th><th class="py-2 pr-4">Terlambat</th><th class="py-2 text-right">Harga</th>
                </tr></thead>
                <tbody>
                    @foreach ($overdue as $s)
                        <tr class="border-t border-slate-100 dark:border-slate-800">
                            <td class="py-2 pr-4"><a href="{{ route('clients.show', $s->client) }}" class="hover:text-brand-600">{{ $s->client?->name ?? '—' }}</a></td>
                            <td class="py-2 pr-4"><a href="{{ route('services.show', $s) }}" class="font-medium text-brand-600 hover:underline">{{ $s->name }}</a></td>
                            <td class="py-2 pr-4">{{ $s->type->label() }}</td>
                            <td class="py-2 pr-4">{{ tgl_id($s->end_date) }}</td>
                            <td class="py-2 pr-4 font-semibold text-red-600">{{ abs($s->daysUntilEnd()) }} hari</td>
                            <td class="py-2 text-right">{{ rupiah($s->price) }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </div>
@endif

<div class="rounded-xl border border-slate-200 bg-white p-5 dark:border-slate-800 dark:bg-slate-900">
    <h2 class="mb-3 font-bold text-amber-700 dark:text-amber-300">Jatuh tempo ≤ {{ $days }} hari ({{ $expiring->count() }})</h2>
    @if ($expiring->isEmpty())
        <p class="text-sm text-slate-500">Tidak ada layanan yang jatuh tempo dalam {{ $days }} hari.</p>
    @else
        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead><tr class="text-left text-xs uppercase text-slate-500">
                    <th class="py-2 pr-4">Klien</th><th class="py-2 pr-4">Layanan</th><th class="py-2 pr-4">Jenis</th><th class="py-2 pr-4">Berakhir</th><th class="py-2 pr-4">Sisa</th><th class="py-2 text-right">Harga</th>
                </tr></thead>
                <tbody>
                    @foreach ($expiring as $s)
                        <tr class="border-t border-slate-100 dark:border-slate-800">
                            <td class="py-2 pr-4"><a href="{{ route('clients.show', $s->client) }}" class="hover:text-brand-600">{{ $s->client?->name ?? '—' }}</a></td>
                            <td class="py-2 pr-4"><a href="{{ route('services.show', $s) }}" class="font-medium text-brand-600 hover:underline">{{ $s->name }}</a></td>
                            <td class="py-2 pr-4">{{ $s->type->label() }}</td>
                            <td class="py-2 pr-4">{{ tgl_id($s->end_date) }}</td>
                            <td class="py-2 pr-4 font-semibold text-amber-600">{{ $s->daysUntilEnd() }} hari</td>
                            <td class="py-2 text-right">{{ rupiah($s->price) }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif
</div>
@endsection
