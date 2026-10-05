@extends('layouts.app')

@section('title', 'Laporan')

@section('content')
@php
    $totalIncome = array_sum(array_column($monthlyIncome, 'total'));
    $totalOutstanding = $grandTotals['outstanding_amount'];
@endphp

<x-page-header title="Laporan" icon="file-text"
               subtitle="Ringkasan pemasukan, piutang, dan performa per klien." />

<div class="grid gap-4 sm:grid-cols-3">
    <x-stat-card label="Pemasukan 12 bulan" :value="rupiah($totalIncome)" icon="receipt-text" />
    <x-stat-card label="Invoice belum lunas" :value="$unpaidTotal . ' tagihan'" icon="inbox" />
    <x-stat-card label="Total piutang" :value="rupiah($totalOutstanding)" icon="receipt-text" />
</div>

{{-- Pemasukan per bulan --}}
<x-card class="mt-6">
    <x-slot:header>
        <h2 class="font-bold">Pemasukan per bulan</h2>
    </x-slot:header>
    <x-table>
        <thead><tr>
            <th>Bulan</th>
            <th class="text-right">Pemasukan</th>
        </tr></thead>
        <tbody>
            @foreach ($monthlyIncome as $row)
                <tr class="{{ $row['is_current'] ? 'bg-brand-50 font-semibold dark:bg-brand-950/40' : '' }}">
                    <td>
                        {{ $row['label'] }}
                        @if ($row['is_current'])
                            <x-badge variant="info" class="ml-1 text-[11px]">bulan ini</x-badge>
                        @endif
                    </td>
                    <td class="text-right">{{ rupiah($row['total']) }}</td>
                </tr>
            @endforeach
        </tbody>
    </x-table>
</x-card>

{{-- Invoice belum lunas --}}
<x-card class="mt-6">
    <x-slot:header>
        <h2 class="font-bold">Invoice belum lunas</h2>
    </x-slot:header>
    @if ($unpaidInvoices->isEmpty())
        <x-empty-state title="Tidak ada invoice yang belum lunas." icon="receipt-text" />
    @else
        <x-table>
            <thead><tr>
                <th>Nomor</th>
                <th>Klien</th>
                <th class="text-right">Total</th>
                <th>Jatuh tempo</th>
                <th>Umur keterlambatan</th>
            </tr></thead>
            <tbody>
                @foreach ($unpaidInvoices as $row)
                    @php $invoice = $row['invoice']; @endphp
                    <tr class="{{ $row['is_overdue'] ? 'bg-red-50 dark:bg-red-950/40' : '' }}">
                        <td>
                            <a href="{{ route('invoices.show', $invoice) }}" class="font-semibold text-brand-600 hover:underline">{{ $invoice->number }}</a>
                            @if ($invoice->title)<p class="text-xs text-slate-400">{{ $invoice->title }}</p>@endif
                        </td>
                        <td>{{ $invoice->client?->name ?? '—' }}</td>
                        <td class="text-right">{{ rupiah($invoice->total) }}</td>
                        <td>{{ tgl_id($invoice->due_date) }}</td>
                        <td>
                            @if ($row['is_overdue'])
                                <x-badge variant="danger">{{ $row['days_late'] }} hari lewat</x-badge>
                            @else
                                <span class="text-xs text-slate-400">Belum jatuh tempo</span>
                            @endif
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </x-table>

        @if ($unpaidInvoices->hasPages())
            <div class="mt-4">{{ $unpaidInvoices->links() }}</div>
        @endif
    @endif
</x-card>

{{-- Ringkasan per klien --}}
<x-card class="mt-6">
    <x-slot:header>
        <h2 class="font-bold">Ringkasan per klien</h2>
    </x-slot:header>
    @if ($clientSummaries->isEmpty())
        <x-empty-state title="Belum ada invoice." icon="receipt-text" />
    @else
        <x-table>
            <thead><tr>
                <th>Klien</th>
                <th class="text-right">Total invoice</th>
                <th class="text-right">Lunas</th>
                <th class="text-right">Outstanding</th>
            </tr></thead>
            <tbody>
                @foreach ($clientSummaries as $summary)
                    <tr>
                        <td>
                            @if ($summary->client)
                                <a href="{{ route('clients.show', $summary->client) }}" class="font-semibold text-brand-600 hover:underline">{{ $summary->client->name }}</a>
                            @else
                                —
                            @endif
                        </td>
                        <td class="text-right">{{ rupiah($summary->total_amount) }}</td>
                        <td class="text-right text-green-700 dark:text-green-300">{{ rupiah($summary->paid_amount) }}</td>
                        <td class="text-right font-semibold {{ $summary->outstanding_amount > 0 ? 'text-red-600' : 'text-slate-400' }}">{{ rupiah($summary->outstanding_amount) }}</td>
                    </tr>
                @endforeach
            </tbody>
            <tfoot>
                {{-- Total GLOBAL: agregat seluruh invoice, bukan penjumlahan baris
                     per halaman (tetap akurat walau tabel dipaginasi). --}}
                <tr class="border-t font-semibold">
                    <td>Total</td>
                    <td class="text-right">{{ rupiah($grandTotals['total_amount']) }}</td>
                    <td class="text-right">{{ rupiah($grandTotals['paid_amount']) }}</td>
                    <td class="text-right">{{ rupiah($grandTotals['outstanding_amount']) }}</td>
                </tr>
            </tfoot>
        </x-table>

        @if ($clientSummaries->hasPages())
            <div class="mt-4">{{ $clientSummaries->links() }}</div>
        @endif
    @endif
</x-card>
@endsection
