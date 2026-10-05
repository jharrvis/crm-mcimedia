@extends('layouts.app')

@section('title', 'Invoice')

@section('content')
@php
    use App\Domains\Invoicing\Enums\InvoiceStatus;
@endphp

<x-page-header title="Invoice" subtitle="Tagihan ke klien MCI Media.">
    <x-btn :href="route('invoices.create')" icon="plus">Buat invoice</x-btn>
</x-page-header>

<x-card>
    <form method="GET" action="{{ route('invoices.index') }}" class="mb-4 grid gap-2 sm:grid-cols-2 lg:grid-cols-4">
        <input name="q" value="{{ request('q') }}" placeholder="Cari nomor / klien…"
               class="w-full rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm placeholder:text-slate-400 focus:border-brand-500 focus:outline-none focus:ring-2 focus:ring-brand-100 dark:border-slate-700 dark:bg-slate-800 dark:text-slate-100 dark:focus:border-brand-400 dark:focus:ring-brand-900">
        <select name="client_id"
                class="w-full rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm focus:border-brand-500 focus:outline-none focus:ring-2 focus:ring-brand-100 dark:border-slate-700 dark:bg-slate-800 dark:text-slate-100">
            <option value="">Semua klien</option>
            @foreach ($clients as $c)
                <option value="{{ $c->id }}" @selected(request('client_id') == $c->id)>{{ $c->name }}</option>
            @endforeach
        </select>
        <select name="status"
                class="w-full rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm focus:border-brand-500 focus:outline-none focus:ring-2 focus:ring-brand-100 dark:border-slate-700 dark:bg-slate-800 dark:text-slate-100">
            <option value="all" @selected(request('status') === 'all')>Semua status</option>
            @foreach ($statuses as $s)
                <option value="{{ $s->value }}" @selected(request('status') === $s->value)>{{ $s->label() }}</option>
            @endforeach
        </select>
        <x-btn type="submit" variant="dark" icon="search">Filter</x-btn>
    </form>

    <x-table>
        <thead><tr>
            <th>Nomor</th>
            <th>Klien</th>
            <th>Terbit</th>
            <th>Jatuh tempo</th>
            <th class="text-right">Total</th>
            <th>Status</th>
            <th class="text-right">Aksi</th>
        </tr></thead>
        <tbody>
            @forelse ($invoices as $invoice)
                @php
                    $isOverdue = $invoice->status === InvoiceStatus::Overdue
                        || ($invoice->status === InvoiceStatus::Sent && $invoice->due_date->isBefore(today()));
                    // Mapping status ke varian badge design system.
                    $statusVariant = match ($invoice->status->value) {
                        'paid' => 'success',
                        'sent' => 'info',
                        'overdue' => 'danger',
                        'cancelled' => 'warning',
                        default => 'slate',
                    };
                @endphp
                <tr @class(['bg-red-50 dark:bg-red-950/40' => $isOverdue])>
                    <td>
                        <a href="{{ route('invoices.show', $invoice) }}" class="font-semibold text-slate-900 hover:text-brand-600 dark:text-white">{{ $invoice->number }}</a>
                        @if ($invoice->title)<p class="text-xs text-slate-400">{{ $invoice->title }}</p>@endif
                    </td>
                    <td><a href="{{ route('clients.show', $invoice->client) }}" class="hover:text-brand-600">{{ $invoice->client?->name ?? '—' }}</a></td>
                    <td>{{ tgl_id($invoice->issue_date) }}</td>
                    <td>
                        {{ tgl_id($invoice->due_date) }}
                        @if ($isOverdue)
                            <x-badge variant="danger" :dot="true" class="ml-1">lewat</x-badge>
                        @endif
                    </td>
                    <td class="text-right tabular-nums">{{ rupiah($invoice->total) }}</td>
                    <td>
                        <x-badge :variant="$statusVariant" :dot="true">{{ $invoice->status->label() }}</x-badge>
                    </td>
                    <td class="whitespace-nowrap text-right">
                        <a href="{{ route('invoices.show', $invoice) }}" class="text-sm font-semibold text-brand-600 hover:underline">Detail</a>
                        <span class="mx-1 text-slate-300">|</span>
                        <a href="{{ route('invoices.pdf', $invoice) }}" class="text-sm font-semibold text-brand-600 hover:underline">PDF</a>
                        @if ($invoice->status === InvoiceStatus::Draft)
                            <span class="mx-1 text-slate-300">|</span>
                            <form method="POST" action="{{ route('invoices.destroy', $invoice) }}" class="inline" onsubmit="return confirm('Hapus invoice {{ $invoice->number }}? Tindakan ini tidak dapat dibatalkan.')">
                                @csrf
                                @method('DELETE')
                                <button type="submit" class="text-sm font-semibold text-rose-600 hover:underline">Hapus</button>
                            </form>
                        @endif
                    </td>
                </tr>
            @empty
                <tr><td colspan="7">
                    <x-empty-state title="Belum ada invoice" icon="receipt-text">
                        Belum ada invoice yang cocok dengan filter.
                        <x-slot:action>
                            <x-btn :href="route('invoices.create')" icon="plus">Buat invoice</x-btn>
                        </x-slot:action>
                    </x-empty-state>
                </td></tr>
            @endforelse
        </tbody>
    </x-table>

    @if ($invoices->hasPages())
        <div class="mt-4">{{ $invoices->links() }}</div>
    @endif
</x-card>
@endsection