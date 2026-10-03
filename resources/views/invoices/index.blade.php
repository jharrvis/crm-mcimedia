@extends('layouts.app')

@section('title', 'Invoice')

@section('content')
@php
    use App\Domains\Invoicing\Enums\InvoiceStatus;

    $statusClasses = [
        'draft' => 'bg-slate-200 text-slate-600 dark:bg-slate-700 dark:text-slate-300',
        'sent' => 'bg-blue-100 text-blue-700 dark:bg-blue-900 dark:text-blue-200',
        'paid' => 'bg-green-100 text-green-700 dark:bg-green-900 dark:text-green-200',
        'overdue' => 'bg-red-100 text-red-700 dark:bg-red-900 dark:text-red-200',
        'cancelled' => 'bg-amber-100 text-amber-700 dark:bg-amber-900 dark:text-amber-200',
    ];
@endphp

<div class="mb-4 flex flex-wrap items-center gap-2">
    <a href="{{ route('invoices.create') }}" class="rounded-lg bg-indigo-600 px-4 py-2 text-sm font-semibold text-white hover:bg-indigo-700">Buat invoice</a>
</div>

<form method="GET" action="{{ route('invoices.index') }}" class="mb-4 grid gap-2 rounded-xl border border-slate-200 bg-white p-4 sm:grid-cols-4 dark:border-slate-800 dark:bg-slate-900">
    <input name="q" value="{{ request('q') }}" placeholder="Cari nomor / klien…" class="rounded-lg border border-slate-300 px-3 py-2 text-sm dark:border-slate-700 dark:bg-slate-800">
    <x-client-select
        :clients="$clients"
        :selected="request('client_id')"
        empty-label="Semua klien"
        class="rounded-lg border border-slate-300 px-3 py-2 text-sm dark:border-slate-700 dark:bg-slate-800" />
    <select name="status" class="rounded-lg border border-slate-300 px-3 py-2 text-sm dark:border-slate-700 dark:bg-slate-800">
        <option value="all" @selected(request('status') === 'all')>Semua status</option>
        @foreach ($statuses as $s)
            <option value="{{ $s->value }}" @selected(request('status') === $s->value)>{{ $s->label() }}</option>
        @endforeach
    </select>
    <button class="rounded-lg bg-slate-800 px-4 py-2 text-sm font-semibold text-white hover:bg-slate-700 dark:bg-slate-700">Filter</button>
</form>

<div class="overflow-x-auto rounded-xl border border-slate-200 bg-white dark:border-slate-800 dark:bg-slate-900">
    <table class="w-full text-sm">
        <thead>
            <tr class="text-left text-xs uppercase text-slate-500">
                <th class="px-4 py-3">Nomor</th>
                <th class="px-4 py-3">Klien</th>
                <th class="px-4 py-3">Terbit</th>
                <th class="px-4 py-3">Jatuh tempo</th>
                <th class="px-4 py-3 text-right">Total</th>
                <th class="px-4 py-3">Status</th>
                <th class="px-4 py-3 text-right">Aksi</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($invoices as $invoice)
                @php
                    $isOverdue = $invoice->status === InvoiceStatus::Overdue
                        || ($invoice->status === InvoiceStatus::Sent && $invoice->due_date->isBefore(today()));
                    $rowClass = $isOverdue ? 'bg-red-50 dark:bg-red-950/40' : '';
                @endphp
                <tr class="border-t border-slate-100 dark:border-slate-800 {{ $rowClass }}">
                    <td class="px-4 py-3">
                        <a href="{{ route('invoices.show', $invoice) }}" class="font-medium text-indigo-600 hover:underline">{{ $invoice->number }}</a>
                        @if ($invoice->title)<p class="text-xs text-slate-500">{{ $invoice->title }}</p>@endif
                    </td>
                    <td class="px-4 py-3"><a href="{{ route('clients.show', $invoice->client) }}" class="hover:text-indigo-600">{{ $invoice->client?->name ?? '—' }}</a></td>
                    <td class="px-4 py-3">{{ tgl_id($invoice->issue_date) }}</td>
                    <td class="px-4 py-3">
                        {{ tgl_id($invoice->due_date) }}
                        @if ($isOverdue)
                            <span class="ml-1 rounded-full bg-red-100 px-2 py-0.5 text-xs font-semibold text-red-700 dark:bg-red-900 dark:text-red-200">lewat</span>
                        @endif
                    </td>
                    <td class="px-4 py-3 text-right">{{ rupiah($invoice->total) }}</td>
                    <td class="px-4 py-3">
                        <span class="rounded-full px-2 py-0.5 text-xs font-medium {{ $statusClasses[$invoice->status->value] }}">{{ $invoice->status->label() }}</span>
                    </td>
                    <td class="px-4 py-3 text-right whitespace-nowrap">
                        <a href="{{ route('invoices.show', $invoice) }}" class="text-indigo-600 hover:underline">Detail</a>
                        <span class="mx-1 text-slate-300">|</span>
                        <a href="{{ route('invoices.pdf', $invoice) }}" class="text-indigo-600 hover:underline">PDF</a>
                        @if ($invoice->status === InvoiceStatus::Draft)
                            <span class="mx-1 text-slate-300">|</span>
                            <form method="POST" action="{{ route('invoices.destroy', $invoice) }}" class="inline" onsubmit="return confirm('Hapus invoice {{ $invoice->number }}? Tindakan ini tidak dapat dibatalkan.')">
                                @csrf
                                @method('DELETE')
                                <button class="text-red-600 hover:underline">Hapus</button>
                            </form>
                        @endif
                    </td>
                </tr>
            @empty
                <tr><td colspan="7" class="px-4 py-8 text-center text-slate-500">Belum ada invoice.</td></tr>
            @endforelse
        </tbody>
    </table>
</div>
<div class="mt-4">{{ $invoices->links() }}</div>
@endsection
