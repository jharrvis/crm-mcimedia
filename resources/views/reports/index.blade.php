@extends('layouts.app')

@section('title', 'Laporan')

@section('content')
@php
    use App\Domains\Invoicing\Enums\InvoiceStatus;

    $totalIncome = array_sum(array_column($monthlyIncome, 'total'));
    $totalOutstanding = $clientSummaries->sum('outstanding_amount');
@endphp

<div class="grid gap-4 sm:grid-cols-3">
    <div class="rounded-xl border border-slate-200 bg-white p-5 dark:border-slate-800 dark:bg-slate-900">
        <p class="text-sm text-slate-500 dark:text-slate-400">Pemasukan 12 bulan</p>
        <p class="mt-1 text-2xl font-bold">{{ rupiah($totalIncome) }}</p>
    </div>
    <div class="rounded-xl border border-slate-200 bg-white p-5 dark:border-slate-800 dark:bg-slate-900">
        <p class="text-sm text-slate-500 dark:text-slate-400">Invoice belum lunas</p>
        <p class="mt-1 text-2xl font-bold">{{ $unpaidInvoices->count() }} <span class="text-base font-medium text-slate-500">tagihan</span></p>
    </div>
    <div class="rounded-xl border border-slate-200 bg-white p-5 dark:border-slate-800 dark:bg-slate-900">
        <p class="text-sm text-slate-500 dark:text-slate-400">Total piutang</p>
        <p class="mt-1 text-2xl font-bold text-red-600">{{ rupiah($totalOutstanding) }}</p>
    </div>
</div>

{{-- Pemasukan per bulan --}}
<div class="mt-6 rounded-xl border border-slate-200 bg-white p-5 dark:border-slate-800 dark:bg-slate-900">
    <h2 class="mb-3 font-bold">Pemasukan per bulan</h2>
    <div class="overflow-x-auto">
        <table class="w-full text-sm">
            <thead>
                <tr class="text-left text-xs uppercase text-slate-500">
                    <th class="px-4 py-3">Bulan</th>
                    <th class="px-4 py-3 text-right">Pemasukan</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($monthlyIncome as $row)
                    <tr class="border-t border-slate-100 dark:border-slate-800 {{ $row['is_current'] ? 'bg-brand-50 font-semibold dark:bg-brand-950/40' : '' }}">
                        <td class="px-4 py-3">
                            {{ $row['label'] }}
                            @if ($row['is_current'])
                                <span class="ml-1 rounded-full bg-brand-600 px-2 py-0.5 text-[11px] font-bold text-white">bulan ini</span>
                            @endif
                        </td>
                        <td class="px-4 py-3 text-right">{{ rupiah($row['total']) }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
</div>

{{-- Invoice belum lunas --}}
<div class="mt-6 rounded-xl border border-slate-200 bg-white p-5 dark:border-slate-800 dark:bg-slate-900">
    <h2 class="mb-3 font-bold">Invoice belum lunas</h2>
    @if ($unpaidInvoices->isEmpty())
        <p class="text-sm text-slate-500">Tidak ada invoice yang belum lunas.</p>
    @else
        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead>
                    <tr class="text-left text-xs uppercase text-slate-500">
                        <th class="px-4 py-3">Nomor</th>
                        <th class="px-4 py-3">Klien</th>
                        <th class="px-4 py-3 text-right">Total</th>
                        <th class="px-4 py-3">Jatuh tempo</th>
                        <th class="px-4 py-3">Umur keterlambatan</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($unpaidInvoices as $row)
                        @php $invoice = $row['invoice']; @endphp
                        <tr class="border-t border-slate-100 dark:border-slate-800 {{ $row['is_overdue'] ? 'bg-red-50 dark:bg-red-950/40' : '' }}">
                            <td class="px-4 py-3">
                                <a href="{{ route('invoices.show', $invoice) }}" class="font-medium text-brand-600 hover:underline">{{ $invoice->number }}</a>
                                @if ($invoice->title)<p class="text-xs text-slate-500">{{ $invoice->title }}</p>@endif
                            </td>
                            <td class="px-4 py-3">{{ $invoice->client?->name ?? '—' }}</td>
                            <td class="px-4 py-3 text-right">{{ rupiah($invoice->total) }}</td>
                            <td class="px-4 py-3">{{ tgl_id($invoice->due_date) }}</td>
                            <td class="px-4 py-3">
                                @if ($row['is_overdue'])
                                    <span class="rounded-full bg-red-100 px-2 py-0.5 text-xs font-semibold text-red-700 dark:bg-red-900 dark:text-red-200">{{ $row['days_late'] }} hari lewat</span>
                                @else
                                    <span class="text-xs text-slate-500">Belum jatuh tempo</span>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif
</div>

{{-- Ringkasan per klien --}}
<div class="mt-6 rounded-xl border border-slate-200 bg-white p-5 dark:border-slate-800 dark:bg-slate-900">
    <h2 class="mb-3 font-bold">Ringkasan per klien</h2>
    @if ($clientSummaries->isEmpty())
        <p class="text-sm text-slate-500">Belum ada invoice.</p>
    @else
        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead>
                    <tr class="text-left text-xs uppercase text-slate-500">
                        <th class="px-4 py-3">Klien</th>
                        <th class="px-4 py-3 text-right">Total invoice</th>
                        <th class="px-4 py-3 text-right">Lunas</th>
                        <th class="px-4 py-3 text-right">Outstanding</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($clientSummaries as $summary)
                        <tr class="border-t border-slate-100 dark:border-slate-800">
                            <td class="px-4 py-3">
                                @if ($summary->client)
                                    <a href="{{ route('clients.show', $summary->client) }}" class="font-medium text-brand-600 hover:underline">{{ $summary->client->name }}</a>
                                @else
                                    —
                                @endif
                            </td>
                            <td class="px-4 py-3 text-right">{{ rupiah($summary->total_amount) }}</td>
                            <td class="px-4 py-3 text-right text-green-700 dark:text-green-300">{{ rupiah($summary->paid_amount) }}</td>
                            <td class="px-4 py-3 text-right font-semibold {{ $summary->outstanding_amount > 0 ? 'text-red-600' : 'text-slate-500' }}">{{ rupiah($summary->outstanding_amount) }}</td>
                        </tr>
                    @endforeach
                </tbody>
                <tfoot>
                    <tr class="border-t border-slate-200 font-semibold dark:border-slate-700">
                        <td class="px-4 py-3">Total</td>
                        <td class="px-4 py-3 text-right">{{ rupiah($clientSummaries->sum('total_amount')) }}</td>
                        <td class="px-4 py-3 text-right">{{ rupiah($clientSummaries->sum('paid_amount')) }}</td>
                        <td class="px-4 py-3 text-right">{{ rupiah($totalOutstanding) }}</td>
                    </tr>
                </tfoot>
            </table>
        </div>
    @endif
</div>
@endsection
