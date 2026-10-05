@extends('layouts.public')

@section('title', 'Invoice '.$invoice->number)

@section('content')
@php
    use App\Domains\Invoicing\Enums\InvoiceStatus;

    $statusClasses = [
        'draft' => 'bg-slate-200 text-slate-600',
        'sent' => 'bg-blue-100 text-blue-700',
        'paid' => 'bg-green-100 text-green-700',
        'overdue' => 'bg-red-100 text-red-700',
        'cancelled' => 'bg-amber-100 text-amber-700',
    ];
    $isPaid = $invoice->status === InvoiceStatus::Paid;
    $isOverdue = $invoice->status === InvoiceStatus::Overdue
        || ($invoice->status === InvoiceStatus::Sent && $invoice->due_date->isBefore(today()));
    $bankAccounts = crm_bank_accounts();
@endphp

<div class="space-y-4">

    <!-- Kop identitas usaha + nomor invoice -->
    <div class="rounded-xl border border-slate-200 bg-white p-6 dark:border-slate-800 dark:bg-slate-900">
        <div class="flex flex-wrap items-start justify-between gap-4">
            <div>
                <p class="text-lg font-bold text-indigo-600">{{ $business['name'] }}</p>
                <p class="text-xs text-slate-500">{{ $business['address'] }}</p>
                <p class="text-xs text-slate-500">{{ $business['email'] }} · {{ $business['phone'] }}</p>
            </div>
            <div class="text-right">
                <p class="text-xs uppercase tracking-wide text-slate-500">Invoice</p>
                <p class="text-xl font-bold">{{ $invoice->number }}</p>
                <span class="mt-1 inline-block rounded-full px-3 py-1 text-sm font-semibold {{ $statusClasses[$invoice->status->value] ?? 'bg-slate-200 text-slate-600' }}">
                    {{ $invoice->status->label() }}
                </span>
            </div>
        </div>

        <dl class="mt-6 grid gap-x-6 gap-y-4 text-sm sm:grid-cols-3">
            <div><dt class="text-slate-500">Ditagihkan kepada</dt><dd class="font-medium">{{ $invoice->client?->name ?? '—' }}</dd></div>
            <div><dt class="text-slate-500">Tanggal terbit</dt><dd class="font-medium">{{ tgl_id($invoice->issue_date) }}</dd></div>
            <div>
                <dt class="text-slate-500">Jatuh tempo</dt>
                <dd class="font-medium">
                    {{ tgl_id($invoice->due_date) }}
                    @if ($isOverdue && ! $isPaid)
                        <span class="ml-1 rounded-full bg-red-100 px-2 py-0.5 text-xs font-semibold text-red-700">lewat</span>
                    @endif
                </dd>
            </div>
        </dl>

        @if ($invoice->title)
            <p class="mt-4 text-sm text-slate-500">Perihal: <span class="font-medium text-slate-700 dark:text-slate-200">{{ $invoice->title }}</span></p>
        @endif

        @if ($isPaid)
            <div class="mt-4 rounded-lg border border-green-200 bg-green-50 px-4 py-3 text-sm font-semibold text-green-800">
                LUNAS — pembayaran invoice ini sudah kami terima. Terima kasih.
            </div>
        @endif
    </div>

    <!-- Item + total -->
    <div class="overflow-x-auto rounded-xl border border-slate-200 bg-white dark:border-slate-800 dark:bg-slate-900">
        <table class="w-full text-sm">
            <thead>
                <tr class="text-left text-xs uppercase text-slate-500">
                    <th class="px-4 py-3">Deskripsi</th>
                    <th class="px-4 py-3 text-center">Qty</th>
                    <th class="px-4 py-3 text-right">Harga satuan</th>
                    <th class="px-4 py-3 text-right">Jumlah</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($invoice->items as $item)
                    <tr class="border-t border-slate-100 dark:border-slate-800">
                        <td class="px-4 py-3">{{ $item->description }}</td>
                        <td class="px-4 py-3 text-center">{{ $item->quantity }}</td>
                        <td class="px-4 py-3 text-right tabular-nums">{{ rupiah($item->unit_price) }}</td>
                        <td class="px-4 py-3 text-right tabular-nums">{{ rupiah($item->amount) }}</td>
                    </tr>
                @empty
                    <tr><td colspan="4" class="px-4 py-6 text-center text-slate-500">Belum ada item.</td></tr>
                @endforelse
            </tbody>
            <tfoot>
                <tr class="border-t border-slate-200 dark:border-slate-700">
                    <td colspan="3" class="px-4 py-3 text-right font-semibold">Total</td>
                    <td class="px-4 py-3 text-right font-bold tabular-nums">{{ rupiah($invoice->total) }}</td>
                </tr>
            </tfoot>
        </table>
    </div>

    <!-- Unduh PDF -->
    <div>
        <a href="{{ route('invoices.public.pdf', ['token' => $invoice->public_token]) }}"
           class="inline-block rounded-lg border border-slate-300 px-4 py-2 text-sm hover:bg-slate-50 dark:border-slate-700 dark:hover:bg-slate-800">
            Unduh PDF invoice
        </a>
    </div>

    @unless ($isPaid)
        <!-- Instruksi transfer -->
        <div class="rounded-xl border border-slate-200 bg-white p-6 dark:border-slate-800 dark:bg-slate-900">
            <h2 class="mb-3 text-sm font-semibold uppercase text-slate-500">Instruksi transfer</h2>
            @if ($bankAccounts !== [])
                <p class="text-sm text-slate-600 dark:text-slate-300">
                    Mohon transfer ke <strong>salah satu</strong> rekening berikut dan sertakan nomor invoice
                    <strong>{{ $invoice->number }}</strong> pada berita transfer.
                </p>
                <div class="mt-3 grid gap-3 text-sm sm:grid-cols-2">
                    @foreach ($bankAccounts as $account)
                        <div class="rounded-lg border border-slate-200 bg-slate-50 p-4 dark:border-slate-700 dark:bg-slate-800">
                            <p class="font-semibold text-slate-700 dark:text-slate-200">{{ $account['name'] }}</p>
                            <p class="mt-1 font-mono text-base font-semibold tracking-wide">{{ $account['account_number'] }}</p>
                            <p class="mt-1 text-xs text-slate-500">a.n. {{ $account['account_holder'] }}</p>
                        </div>
                    @endforeach
                </div>
                <dl class="mt-4 grid gap-x-6 gap-y-2 text-sm sm:grid-cols-2">
                    <div><dt class="text-slate-500">Jumlah</dt><dd class="font-semibold">{{ rupiah($invoice->total) }}</dd></div>
                </dl>
            @else
                <p class="text-sm text-slate-600 dark:text-slate-300">
                    Detail rekening tujuan belum tersedia. Silakan hubungi
                    <strong>{{ $business['email'] }}</strong> untuk informasi pembayaran.
                </p>
            @endif
        </div>

        <!-- Form konfirmasi transfer -->
        <div class="rounded-xl border border-slate-200 bg-white p-6 dark:border-slate-800 dark:bg-slate-900">
            <h2 class="mb-1 text-sm font-semibold uppercase text-slate-500">Konfirmasi transfer</h2>
            <p class="mb-4 text-sm text-slate-500">
                Sudah transfer? Isi form berikut. Admin akan memverifikasi dan menandai invoice lunas.
            </p>

            @if ($errors->any())
                <div class="mb-4 rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700">
                    <ul class="list-inside list-disc space-y-1">
                        @foreach ($errors->all() as $error)
                            <li>{{ $error }}</li>
                        @endforeach
                    </ul>
                </div>
            @endif

            @include('invoices._midtrans_pay')

            <form method="POST" action="{{ route('invoices.public.payments.store', ['token' => $invoice->public_token]) }}" class="grid gap-3 sm:grid-cols-2">
                @csrf
                <div>
                    <label class="mb-1 block text-sm font-medium">Nama pengirim <span class="text-red-600">*</span></label>
                    <input name="sender_name" value="{{ old('sender_name') }}" required class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm dark:border-slate-700 dark:bg-slate-800">
                </div>
                <div>
                    <label class="mb-1 block text-sm font-medium">Nominal (IDR) <span class="text-red-600">*</span></label>
                    <input name="amount" type="number" min="1" step="1" required value="{{ old('amount', $invoice->total) }}" class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm dark:border-slate-700 dark:bg-slate-800">
                </div>
                <div>
                    <label class="mb-1 block text-sm font-medium">Tanggal transfer <span class="text-red-600">*</span></label>
                    <input name="paid_at" type="date" required value="{{ old('paid_at', now()->toDateString()) }}" class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm dark:border-slate-700 dark:bg-slate-800">
                </div>
                <div>
                    <label class="mb-1 block text-sm font-medium">Catatan</label>
                    <input name="note" value="{{ old('note') }}" placeholder="opsional" class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm dark:border-slate-700 dark:bg-slate-800">
                </div>
                <div class="sm:col-span-2">
                    <button class="rounded-lg bg-indigo-600 px-4 py-2 text-sm font-semibold text-white hover:bg-indigo-700">
                        Kirim konfirmasi transfer
                    </button>
                </div>
            </form>
        </div>
    @endunless
</div>
@endsection
