@extends('layouts.app')

@section('title', $plan->title)

@section('content')
@php
    $days = $plan->daysUntilNext();
    $canBill = $plan->active && ! $plan->nextPeriodStart()->isFuture();
    $invoiceStatusVariant = ['draft' => 'slate', 'sent' => 'info', 'paid' => 'success', 'overdue' => 'danger', 'cancelled' => 'warning'];
@endphp

<x-page-header :title="$plan->title" icon="repeat" back="{{ route('recurring-plans.index') }}" backLabel="Kembali ke daftar paket">
    <a href="{{ route('recurring-plans.edit', $plan) }}"
       class="inline-flex items-center gap-2 rounded-xl border border-slate-200 bg-white px-4 py-2.5 text-sm font-semibold text-slate-700 transition hover:bg-slate-100 dark:border-slate-700 dark:text-slate-200 dark:hover:bg-slate-800">Ubah</a>

    @if ($plan->active)
        <form method="POST" action="{{ route('recurring-plans.generate-now', $plan) }}" class="inline"
              onsubmit="return confirm('Terbitkan invoice untuk periode berjalan sekarang?')">
            @csrf
            <button type="submit"
                   @if (! $canBill) disabled @endif
                   title="{{ $canBill ? 'Buat invoice periode ini sekarang' : 'Periode berikutnya belum dimulai' }}"
                   class="inline-flex items-center gap-2 rounded-xl px-4 py-2.5 text-sm font-semibold text-white transition disabled:cursor-not-allowed disabled:bg-slate-300 dark:disabled:bg-slate-700 {{ $canBill ? 'bg-brand-600 hover:bg-brand-700' : 'bg-slate-300 dark:bg-slate-700' }}">
                Tagih sekarang
            </button>
        </form>
    @endif

    <form method="POST" action="{{ route('recurring-plans.toggle', $plan) }}" class="inline">
        @csrf
        <button type="submit" class="inline-flex items-center gap-2 rounded-xl bg-slate-900 px-4 py-2.5 text-sm font-semibold text-white transition hover:bg-slate-800 dark:bg-white dark:text-slate-900 dark:hover:bg-slate-200">{{ $plan->active ? 'Nonaktifkan' : 'Aktifkan' }}</button>
    </form>

    <form method="POST" action="{{ route('recurring-plans.destroy', $plan) }}" class="inline"
          onsubmit="return confirm('Hapus paket ini? Invoice yang sudah terbit tetap tersimpan.')">
        @csrf
        @method('DELETE')
        <button type="submit" class="inline-flex items-center gap-2 rounded-xl bg-rose-600 px-4 py-2.5 text-sm font-semibold text-white transition hover:bg-rose-700">Hapus</button>
    </form>
</x-page-header>

<x-card>
    <x-slot:header>
        <h2 class="font-bold">Detail paket</h2>
        @if ($plan->active)
            <x-badge variant="success" :dot="true">Aktif</x-badge>
        @else
            <x-badge variant="slate">Nonaktif</x-badge>
        @endif
    </x-slot:header>

    <dl class="grid gap-x-6 gap-y-4 text-sm sm:grid-cols-2">
        <div>
            <dt class="text-xs uppercase text-slate-400">Judul paket</dt>
            <dd class="font-medium">{{ $plan->title }}</dd>
        </div>
        <div>
            <dt class="text-xs uppercase text-slate-400">Klien</dt>
            <dd class="font-medium">
                <a href="{{ route('clients.show', $plan->client) }}" class="text-brand-600 hover:underline">{{ $plan->client?->name ?? '—' }}</a>
            </dd>
        </div>
        <div>
            <dt class="text-xs uppercase text-slate-400">Layanan</dt>
            <dd class="font-medium">
                @if ($plan->service)
                    <a href="{{ route('services.show', $plan->service) }}" class="text-brand-600 hover:underline">{{ $plan->service->name }}</a>
                @else
                    —
                @endif
            </dd>
        </div>
        <div>
            <dt class="text-xs uppercase text-slate-400">Siklus tagihan</dt>
            <dd class="font-medium">{{ $plan->cycle->label() }} <span class="text-slate-400">({{ $plan->cycle->shortLabel() }})</span></dd>
        </div>
        <div>
            <dt class="text-xs uppercase text-slate-400">Tagihan berikutnya</dt>
            <dd class="font-medium">
                {{ tgl_id($plan->next_invoice_date) }}
                @if ($plan->active && $days < 0)
                    <x-badge variant="danger" class="ml-1">{{ abs($days) }} hari lewat</x-badge>
                @elseif ($plan->active && $days === 0)
                    <x-badge variant="warning" class="ml-1">hari ini</x-badge>
                @endif
            </dd>
        </div>
        <div>
            <dt class="text-xs uppercase text-slate-400">Periode berjalan</dt>
            <dd class="font-medium">{{ tgl_id($plan->nextPeriodStart()) }} – {{ tgl_id($plan->nextPeriodEnd()) }}</dd>
        </div>
        <div>
            <dt class="text-xs uppercase text-slate-400">Total per periode</dt>
            <dd class="font-medium">{{ rupiah($plan->total) }}</dd>
        </div>
        <div>
            <dt class="text-xs uppercase text-slate-400">Jatuh tempo</dt>
            <dd class="font-medium">{{ $plan->due_days }} hari setelah invoice terbit</dd>
        </div>
        <div>
            <dt class="text-xs uppercase text-slate-400">Terbit otomatis</dt>
            <dd class="font-medium">{{ $plan->auto_send ? 'Ya (langsung terkirim)' : 'Tidak (draf, perlu dikirim manual)' }}</dd>
        </div>
        <div class="sm:col-span-2">
            <dt class="text-xs uppercase text-slate-400">Catatan</dt>
            <dd class="font-medium whitespace-pre-line">{{ $plan->notes ?? '—' }}</dd>
        </div>
    </dl>
</x-card>

<x-card class="mt-6">
    <x-slot:header>
        <h2 class="font-bold">Item per periode</h2>
    </x-slot:header>
    <x-table>
        <thead><tr>
            <th>Deskripsi</th>
            <th>Qty</th>
            <th class="text-right">Harga satuan</th>
            <th class="text-right">Nominal</th>
        </tr></thead>
        <tbody>
            @forelse ($plan->items as $item)
                <tr>
                    <td>{{ $item->description }}</td>
                    <td class="tabular-nums">{{ $item->quantity }}</td>
                    <td class="text-right tabular-nums">{{ rupiah($item->unit_price) }}</td>
                    <td class="text-right tabular-nums font-medium">{{ rupiah($item->amount()) }}</td>
                </tr>
            @empty
                <tr><td colspan="4">
                    <x-empty-state title="Belum ada item" icon="list-checks"
                                   description="Paket ini belum punya item — invoice tidak akan terbit sampai item diisi." />
                </td></tr>
            @endforelse
        </tbody>
    </x-table>
</x-card>

<x-card class="mt-6">
    <x-slot:header>
        <h2 class="font-bold">Riwayat invoice ({{ $invoices->count() }})</h2>
    </x-slot:header>
    <x-table>
        <thead><tr>
            <th>Nomor</th>
            <th>Periode</th>
            <th>Terbit</th>
            <th>Jatuh tempo</th>
            <th class="text-right">Total</th>
            <th>Status</th>
        </tr></thead>
        <tbody>
            @forelse ($invoices as $invoice)
                <tr>
                    <td>
                        <a href="{{ route('invoices.show', $invoice) }}" class="font-semibold text-brand-600 hover:underline">{{ $invoice->number }}</a>
                    </td>
                    <td class="whitespace-nowrap">{{ tgl_id($invoice->period_start) }} – {{ tgl_id($invoice->period_end) }}</td>
                    <td>{{ tgl_id($invoice->issue_date) }}</td>
                    <td>{{ tgl_id($invoice->due_date) }}</td>
                    <td class="text-right tabular-nums">{{ rupiah($invoice->total) }}</td>
                    <td>
                        <x-badge :variant="$invoiceStatusVariant[$invoice->status->value] ?? 'slate'">{{ $invoice->status->label() }}</x-badge>
                    </td>
                </tr>
            @empty
                <tr><td colspan="6">
                    <x-empty-state title="Belum ada invoice" icon="receipt-text"
                                   description="Belum ada invoice yang terbit dari paket ini." />
                </td></tr>
            @endforelse
        </tbody>
    </x-table>
</x-card>
@endsection
