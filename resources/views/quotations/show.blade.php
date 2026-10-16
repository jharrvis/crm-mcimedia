@extends('layouts.app')

@section('title', $quotation->number)

@section('content')
@php
    use App\Domains\Quotation\Enums\QuotationStatus;

    $statusClasses = [
        'draft' => 'bg-slate-200 text-slate-600 dark:bg-slate-700 dark:text-slate-300',
        'sent' => 'bg-blue-100 text-blue-700 dark:bg-blue-900 dark:text-blue-200',
        'accepted' => 'bg-green-100 text-green-700 dark:bg-green-900 dark:text-green-200',
        'rejected' => 'bg-red-100 text-red-700 dark:bg-red-900 dark:text-red-200',
        'expired' => 'bg-amber-100 text-amber-700 dark:bg-amber-900 dark:text-amber-200',
        'converted' => 'bg-purple-100 text-purple-700 dark:bg-purple-900 dark:text-purple-200',
    ];
    $isExpired = $quotation->status === QuotationStatus::Expired
        || ($quotation->status === QuotationStatus::Sent && $quotation->valid_until->isBefore(today()));
@endphp

<div class="max-w-4xl space-y-4">

    <!-- Aksi -->
    <div class="flex flex-wrap items-center gap-2">
        @if ($quotation->status === QuotationStatus::Draft)
            <form method="POST" action="{{ route('quotations.send', $quotation) }}" class="inline">
                @csrf
                @method('PATCH')
                <button class="rounded-lg bg-brand-600 px-4 py-2 text-sm font-semibold text-white hover:bg-brand-700">Tandai terkirim</button>
            </form>
            <a href="{{ route('quotations.edit', $quotation) }}" class="rounded-lg border border-slate-300 px-4 py-2 text-sm hover:bg-slate-50 dark:border-slate-700 dark:hover:bg-slate-800">Ubah</a>

            <form method="POST" action="{{ route('quotations.destroy', $quotation) }}" class="inline" onsubmit="return confirm('Hapus penawaran {{ $quotation->number }}? Tindakan ini tidak dapat dibatalkan.')">
                @csrf
                @method('DELETE')
                <button class="rounded-lg border border-red-300 px-4 py-2 text-sm text-red-600 hover:bg-red-50 dark:border-red-800 dark:hover:bg-red-950">Hapus</button>
            </form>
        @endif

        <a href="{{ route('quotations.pdf', $quotation) }}" class="rounded-lg border border-slate-300 px-4 py-2 text-sm hover:bg-slate-50 dark:border-slate-700 dark:hover:bg-slate-800">Unduh PDF</a>
        <a href="{{ route('quotations.index') }}" class="rounded-lg border border-slate-300 px-4 py-2 text-sm hover:bg-slate-50 dark:border-slate-700 dark:hover:bg-slate-800">Kembali</a>
    </div>

    <!-- Ringkasan -->
    <div class="rounded-xl border border-slate-200 bg-white p-6 dark:border-slate-800 dark:bg-slate-900">
        <div class="mb-4 flex flex-wrap items-center justify-between gap-2">
            <div>
                <p class="text-xs uppercase text-slate-500">Quotation</p>
                <p class="text-xl font-bold">{{ $quotation->number }}</p>
                @if ($quotation->title)<p class="text-sm text-slate-500">{{ $quotation->title }}</p>@endif
            </div>
            <span class="rounded-full px-3 py-1 text-sm font-medium {{ $statusClasses[$quotation->status->value] }}">{{ $quotation->status->label() }}</span>
        </div>

        <dl class="grid gap-x-6 gap-y-4 text-sm sm:grid-cols-2">
            <div><dt class="text-slate-500">Klien</dt><dd class="font-medium"><a href="{{ route('clients.show', $quotation->client) }}" class="text-brand-600 hover:underline">{{ $quotation->client?->name ?? '—' }}</a></dd></div>
            <div><dt class="text-slate-500">Tanggal terbit</dt><dd class="font-medium">{{ tgl_id($quotation->issue_date) }}</dd></div>
            <div>
                <dt class="text-slate-500">Masa berlaku</dt>
                <dd class="font-medium">
                    {{ tgl_id($quotation->valid_until) }}
                    @if ($isExpired)
                        <span class="ml-1 rounded-full bg-amber-100 px-2 py-0.5 text-xs font-semibold text-amber-700 dark:bg-amber-900 dark:text-amber-200">lewat</span>
                    @endif
                </dd>
            </div>
            @if ($quotation->sent_at)
                <div><dt class="text-slate-500">Dikirim</dt><dd class="font-medium">{{ tgl_id($quotation->sent_at) }}</dd></div>
            @endif
            @if ($quotation->responded_at)
                <div><dt class="text-slate-500">Direspons</dt><dd class="font-medium">{{ tgl_id($quotation->responded_at) }}</dd></div>
            @endif
            @if ($quotation->convertedInvoice)
                <div>
                    <dt class="text-slate-500">Invoice konversi</dt>
                    <dd class="font-medium"><a href="{{ route('invoices.show', $quotation->convertedInvoice) }}" class="text-brand-600 hover:underline">{{ $quotation->convertedInvoice->number }}</a></dd>
                </div>
            @endif
            @if ($quotation->notes)
                <div class="sm:col-span-2"><dt class="text-slate-500">Catatan</dt><dd class="font-medium whitespace-pre-line">{{ $quotation->notes }}</dd></div>
            @endif
            @if ($quotation->terms)
                <div class="sm:col-span-2"><dt class="text-slate-500">Syarat &amp; ketentuan</dt><dd class="font-medium whitespace-pre-line">{{ $quotation->terms }}</dd></div>
            @endif
        </dl>
    </div>

    <!-- Item penawaran -->
    <div class="rounded-xl border border-slate-200 bg-white p-6 dark:border-slate-800 dark:bg-slate-900">
        <h2 class="mb-3 text-sm font-semibold uppercase tracking-wide text-slate-500">Item penawaran</h2>
        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead>
                    <tr class="border-b border-slate-200 text-left text-xs uppercase text-slate-500 dark:border-slate-700">
                        <th class="px-3 py-2">Deskripsi</th>
                        <th class="px-3 py-2 text-center">Qty</th>
                        <th class="px-3 py-2 text-right">Harga satuan</th>
                        <th class="px-3 py-2 text-right">Jumlah</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($quotation->items as $item)
                        <tr class="border-b border-slate-100 dark:border-slate-800">
                            <td class="px-3 py-2">{{ $item->description }}</td>
                            <td class="px-3 py-2 text-center tabular-nums">{{ $item->quantity }}</td>
                            <td class="px-3 py-2 text-right tabular-nums">{{ rupiah($item->unit_price) }}</td>
                            <td class="px-3 py-2 text-right font-medium tabular-nums">{{ rupiah($item->amount) }}</td>
                        </tr>
                    @endforeach
                </tbody>
                <tfoot>
                    <tr>
                        <td colspan="3" class="px-3 py-2 text-right text-xs font-semibold uppercase text-slate-500">Subtotal</td>
                        <td class="px-3 py-2 text-right font-medium tabular-nums">{{ rupiah($quotation->subtotal) }}</td>
                    </tr>
                    @if ($quotation->discount > 0)
                        <tr>
                            <td colspan="3" class="px-3 py-2 text-right text-xs font-semibold uppercase text-slate-500">Diskon</td>
                            <td class="px-3 py-2 text-right font-medium tabular-nums text-red-600">−{{ rupiah($quotation->discount) }}</td>
                        </tr>
                    @endif
                    <tr class="border-t border-slate-300 dark:border-slate-600">
                        <td colspan="3" class="px-3 py-2 text-right text-sm font-bold uppercase">Total</td>
                        <td class="px-3 py-2 text-right text-base font-bold tabular-nums">{{ rupiah($quotation->total) }}</td>
                    </tr>
                </tfoot>
            </table>
        </div>
    </div>
</div>
@endsection