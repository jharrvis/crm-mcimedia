@extends('layouts.app')

@section('title', 'Quotation')

@section('content')
@php
    use App\Domains\Quotation\Enums\QuotationStatus;
@endphp

<x-page-header title="Quotation" subtitle="Penawaran harga untuk klien MCI Media.">
    <x-btn :href="route('quotations.create')" icon="plus">Buat quotation</x-btn>
</x-page-header>

<x-card>
    <form method="GET" action="{{ route('quotations.index') }}" class="mb-4 grid gap-2 sm:grid-cols-2 lg:grid-cols-4">
        <input name="q" value="{{ request('q') }}" placeholder="Cari nomor / judul / klien…"
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
            <th>Masa berlaku</th>
            <th class="text-right">Total</th>
            <th>Status</th>
            <th class="text-right">Aksi</th>
        </tr></thead>
        <tbody>
            @forelse ($quotations as $quotation)
                @php
                    $isExpired = $quotation->status === QuotationStatus::Expired
                        || ($quotation->status === QuotationStatus::Sent && $quotation->valid_until->isBefore(today()));
                    // Mapping status ke varian badge design system.
                    $statusVariant = match ($quotation->status->value) {
                        'accepted' => 'success',
                        'sent' => 'info',
                        'rejected' => 'danger',
                        'expired' => 'warning',
                        default => 'slate',
                    };
                @endphp
                <tr @class(['bg-amber-50 dark:bg-amber-950/40' => $isExpired])>
                    <td>
                        <a href="{{ route('quotations.show', $quotation) }}" class="font-semibold text-slate-900 hover:text-brand-600 dark:text-white">{{ $quotation->number }}</a>
                        @if ($quotation->title)<p class="text-xs text-slate-400">{{ $quotation->title }}</p>@endif
                    </td>
                    <td><a href="{{ route('clients.show', $quotation->client) }}" class="hover:text-brand-600">{{ $quotation->client?->name ?? '—' }}</a></td>
                    <td>{{ tgl_id($quotation->issue_date) }}</td>
                    <td>
                        {{ tgl_id($quotation->valid_until) }}
                        @if ($isExpired)
                            <x-badge variant="warning" :dot="true" class="ml-1">lewat</x-badge>
                        @endif
                    </td>
                    <td class="text-right tabular-nums">{{ rupiah($quotation->total) }}</td>
                    <td>
                        <x-badge :variant="$statusVariant" :dot="true">{{ $quotation->status->label() }}</x-badge>
                    </td>
                    <td class="whitespace-nowrap text-right">
                        <a href="{{ route('quotations.show', $quotation) }}" class="text-sm font-semibold text-brand-600 hover:underline">Detail</a>
                        <span class="mx-1 text-slate-300">|</span>
                        <a href="{{ route('quotations.pdf', $quotation) }}" class="text-sm font-semibold text-brand-600 hover:underline">PDF</a>
                        @if ($quotation->status === QuotationStatus::Draft)
                            <span class="mx-1 text-slate-300">|</span>
                            <form method="POST" action="{{ route('quotations.destroy', $quotation) }}" class="inline" onsubmit="return confirm('Hapus penawaran {{ $quotation->number }}? Tindakan ini tidak dapat dibatalkan.')">
                                @csrf
                                @method('DELETE')
                                <button type="submit" class="text-sm font-semibold text-rose-600 hover:underline">Hapus</button>
                            </form>
                        @endif
                    </td>
                </tr>
            @empty
                <tr><td colspan="7">
                    <x-empty-state title="Belum ada quotation" icon="file-text">
                        Belum ada penawaran yang cocok dengan filter.
                        <x-slot:action>
                            <x-btn :href="route('quotations.create')" icon="plus">Buat quotation</x-btn>
                        </x-slot:action>
                    </x-empty-state>
                </td></tr>
            @endforelse
        </tbody>
    </x-table>

    @if ($quotations->hasPages())
        <div class="mt-4">{{ $quotations->links() }}</div>
    @endif
</x-card>
@endsection