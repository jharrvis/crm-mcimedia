@extends('layouts.app')

@section('title', $plan->title)

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

    $days = $plan->daysUntilNext();
    $canBill = $plan->active && ! $plan->nextPeriodStart()->isFuture();
@endphp

<div class="max-w-4xl space-y-4">

    <div class="rounded-xl border border-slate-200 bg-white p-6 dark:border-slate-800 dark:bg-slate-900">
        <div class="mb-4 flex flex-wrap gap-2">
            <a href="{{ route('recurring-plans.edit', $plan) }}" class="rounded-lg bg-brand-600 px-4 py-2 text-sm font-semibold text-white hover:bg-brand-700">Ubah</a>

            @if ($plan->active)
                <form method="POST" action="{{ route('recurring-plans.generate-now', $plan) }}" class="inline"
                      onsubmit="return confirm('Terbitkan invoice untuk periode berjalan sekarang?')">
                    @csrf
                    <button @disabled(! $canBill)
                            title="{{ $canBill ? 'Buat invoice periode ini sekarang' : 'Periode berikutnya belum dimulai' }}"
                            class="rounded-lg bg-green-600 px-4 py-2 text-sm font-semibold text-white hover:bg-green-700 disabled:cursor-not-allowed disabled:bg-slate-300 dark:disabled:bg-slate-700">
                        Tagih sekarang
                    </button>
                </form>
            @endif

            <form method="POST" action="{{ route('recurring-plans.toggle', $plan) }}" class="inline">
                @csrf
                <button class="rounded-lg border border-slate-300 px-4 py-2 text-sm hover:bg-slate-50 dark:border-slate-700 dark:hover:bg-slate-800">
                    {{ $plan->active ? 'Nonaktifkan' : 'Aktifkan' }}
                </button>
            </form>

            <form method="POST" action="{{ route('recurring-plans.destroy', $plan) }}" class="inline"
                  onsubmit="return confirm('Hapus paket ini? Invoice yang sudah terbit tetap tersimpan.')">
                @csrf
                @method('DELETE')
                <button class="rounded-lg border border-red-300 px-4 py-2 text-sm text-red-600 hover:bg-red-50 dark:border-red-800 dark:hover:bg-red-950">Hapus</button>
            </form>

            <a href="{{ route('recurring-plans.index') }}" class="rounded-lg border border-slate-300 px-4 py-2 text-sm hover:bg-slate-50 dark:border-slate-700 dark:hover:bg-slate-800">Kembali</a>
        </div>

        <dl class="grid gap-x-6 gap-y-4 text-sm sm:grid-cols-2">
            <div>
                <dt class="text-slate-500">Judul paket</dt>
                <dd class="font-medium">{{ $plan->title }}</dd>
            </div>
            <div>
                <dt class="text-slate-500">Klien</dt>
                <dd class="font-medium">
                    <a href="{{ route('clients.show', $plan->client) }}" class="text-brand-600 hover:underline">{{ $plan->client?->name ?? '—' }}</a>
                </dd>
            </div>
            <div>
                <dt class="text-slate-500">Layanan</dt>
                <dd class="font-medium">
                    @if ($plan->service)
                        <a href="{{ route('services.show', $plan->service) }}" class="text-brand-600 hover:underline">{{ $plan->service->name }}</a>
                    @else
                        —
                    @endif
                </dd>
            </div>
            <div>
                <dt class="text-slate-500">Siklus tagihan</dt>
                <dd class="font-medium">{{ $plan->cycle->label() }} <span class="text-slate-500">({{ $plan->cycle->shortLabel() }})</span></dd>
            </div>
            <div>
                <dt class="text-slate-500">Tagihan berikutnya</dt>
                <dd class="font-medium">
                    {{ tgl_id($plan->next_invoice_date) }}
                    @if ($plan->active && $days < 0)
                        <span class="ml-1 rounded-full bg-red-100 px-2 py-0.5 text-xs font-semibold text-red-700 dark:bg-red-900 dark:text-red-200">{{ abs($days) }} hari lewat</span>
                    @elseif ($plan->active && $days === 0)
                        <span class="ml-1 rounded-full bg-amber-100 px-2 py-0.5 text-xs font-semibold text-amber-700 dark:bg-amber-900 dark:text-amber-200">hari ini</span>
                    @endif
                </dd>
            </div>
            <div>
                <dt class="text-slate-500">Periode berjalan</dt>
                <dd class="font-medium">{{ tgl_id($plan->nextPeriodStart()) }} – {{ tgl_id($plan->nextPeriodEnd()) }}</dd>
            </div>
            <div>
                <dt class="text-slate-500">Total per periode</dt>
                <dd class="font-medium">{{ rupiah($plan->total) }}</dd>
            </div>
            <div>
                <dt class="text-slate-500">Jatuh tempo</dt>
                <dd class="font-medium">{{ $plan->due_days }} hari setelah invoice terbit</dd>
            </div>
            <div>
                <dt class="text-slate-500">Status</dt>
                <dd class="font-medium">
                    @if ($plan->active)
                        <span class="rounded-full bg-green-100 px-2 py-0.5 text-xs font-semibold text-green-700 dark:bg-green-900 dark:text-green-200">Aktif</span>
                    @else
                        <span class="rounded-full bg-slate-200 px-2 py-0.5 text-xs font-semibold text-slate-600 dark:bg-slate-700 dark:text-slate-300">Nonaktif</span>
                    @endif
                </dd>
            </div>
            <div>
                <dt class="text-slate-500">Terbit otomatis</dt>
                <dd class="font-medium">{{ $plan->auto_send ? 'Ya (langsung terkirim)' : 'Tidak (draf, perlu dikirim manual)' }}</dd>
            </div>
            <div class="sm:col-span-2">
                <dt class="text-slate-500">Catatan</dt>
                <dd class="font-medium whitespace-pre-line">{{ $plan->notes ?? '—' }}</dd>
            </div>
        </dl>
    </div>

    <div class="rounded-xl border border-slate-200 bg-white p-6 dark:border-slate-800 dark:bg-slate-900">
        <h3 class="mb-3 font-bold">Item per periode</h3>
        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead>
                    <tr class="text-left text-xs uppercase text-slate-500">
                        <th class="px-3 py-2">Deskripsi</th>
                        <th class="px-3 py-2">Qty</th>
                        <th class="px-3 py-2 text-right">Harga satuan</th>
                        <th class="px-3 py-2 text-right">Nominal</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($plan->items as $item)
                        <tr class="border-t border-slate-100 dark:border-slate-800">
                            <td class="px-3 py-2">{{ $item->description }}</td>
                            <td class="px-3 py-2 tabular-nums">{{ $item->quantity }}</td>
                            <td class="px-3 py-2 text-right tabular-nums">{{ rupiah($item->unit_price) }}</td>
                            <td class="px-3 py-2 text-right tabular-nums font-medium">{{ rupiah($item->amount()) }}</td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="4" class="px-3 py-4 text-center text-sm text-slate-500">
                                Paket ini belum punya item — invoice tidak akan terbit sampai item diisi.
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    <div class="rounded-xl border border-slate-200 bg-white p-6 dark:border-slate-800 dark:bg-slate-900">
        <h3 class="mb-3 font-bold">Riwayat invoice ({{ $invoices->count() }})</h3>
        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead>
                    <tr class="text-left text-xs uppercase text-slate-500">
                        <th class="px-3 py-2">Nomor</th>
                        <th class="px-3 py-2">Periode</th>
                        <th class="px-3 py-2">Terbit</th>
                        <th class="px-3 py-2">Jatuh tempo</th>
                        <th class="px-3 py-2 text-right">Total</th>
                        <th class="px-3 py-2">Status</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($invoices as $invoice)
                        <tr class="border-t border-slate-100 dark:border-slate-800">
                            <td class="px-3 py-2">
                                <a href="{{ route('invoices.show', $invoice) }}" class="font-medium text-brand-600 hover:underline">{{ $invoice->number }}</a>
                            </td>
                            <td class="px-3 py-2">{{ tgl_id($invoice->period_start) }} – {{ tgl_id($invoice->period_end) }}</td>
                            <td class="px-3 py-2">{{ tgl_id($invoice->issue_date) }}</td>
                            <td class="px-3 py-2">{{ tgl_id($invoice->due_date) }}</td>
                            <td class="px-3 py-2 text-right tabular-nums">{{ rupiah($invoice->total) }}</td>
                            <td class="px-3 py-2">
                                <span class="rounded-full px-2 py-0.5 text-xs font-semibold {{ $statusClasses[$invoice->status->value] }}">
                                    {{ $invoice->status->label() }}
                                </span>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="6" class="px-3 py-4 text-center text-sm text-slate-500">Belum ada invoice yang terbit dari paket ini.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
</div>
@endsection