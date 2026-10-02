@extends('layouts.app')

@section('title', $invoice->number)

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
    $methodLabels = [
        'bank_transfer' => 'Transfer bank',
        'cash' => 'Tunai',
        'qris' => 'QRIS',
        'ewallet' => 'E-wallet',
        'other' => 'Lainnya',
    ];
    $isOverdue = $invoice->status === InvoiceStatus::Overdue
        || ($invoice->status === InvoiceStatus::Sent && $invoice->due_date->isBefore(today()));
@endphp

<div class="max-w-4xl space-y-4">

    <!-- Aksi -->
    <div class="flex flex-wrap items-center gap-2">
        @if ($invoice->status === InvoiceStatus::Draft)
            <form method="POST" action="{{ route('invoices.send', $invoice) }}" class="inline">
                @csrf
                @method('PATCH')
                <button class="rounded-lg bg-indigo-600 px-4 py-2 text-sm font-semibold text-white hover:bg-indigo-700">Tandai terkirim</button>
            </form>
            <a href="{{ route('invoices.edit', $invoice) }}" class="rounded-lg border border-slate-300 px-4 py-2 text-sm hover:bg-slate-50 dark:border-slate-700 dark:hover:bg-slate-800">Ubah</a>

            {{-- Termin pembayaran (F4-10): pecah nilai kontrak jadi beberapa invoice termin --}}
            @if ($invoice->canSplitIntoTerms())
                <a href="{{ route('invoices.termin.create', $invoice) }}"
                   class="rounded-lg border border-purple-300 px-4 py-2 text-sm text-purple-700 hover:bg-purple-50 dark:border-purple-800 dark:text-purple-300 dark:hover:bg-purple-950">
                    Pecah menjadi termin
                </a>
            @endif

            <form method="POST" action="{{ route('invoices.destroy', $invoice) }}" class="inline" onsubmit="return confirm('Hapus invoice {{ $invoice->number }}? Tindakan ini tidak dapat dibatalkan.')">
                @csrf
                @method('DELETE')
                <button class="rounded-lg border border-red-300 px-4 py-2 text-sm text-red-600 hover:bg-red-50 dark:border-red-800 dark:hover:bg-red-950">Hapus</button>
            </form>
        @endif

        @if (! $invoice->isTerminal())
            @if (in_array($invoice->status, [InvoiceStatus::Sent, InvoiceStatus::Overdue], true))
                <button type="button" id="open-payment" class="rounded-lg bg-green-600 px-4 py-2 text-sm font-semibold text-white hover:bg-green-700">Catat pembayaran</button>
            @endif

            {{-- Pengiriman invoice (F2-5): email & WhatsApp --}}
            @php
                $emailTarget = $invoice->client?->email;
                $waTarget = $invoice->client?->whatsapp;
            @endphp
            @if (filled($emailTarget))
                <form method="POST" action="{{ route('invoices.send-email', $invoice) }}" class="inline">
                    @csrf
                    <button class="rounded-lg border border-indigo-300 px-4 py-2 text-sm text-indigo-600 hover:bg-indigo-50 dark:border-indigo-800 dark:hover:bg-indigo-950">Kirim Email</button>
                </form>
            @else
                <button type="button" disabled title="Klien belum punya alamat email"
                        class="cursor-not-allowed rounded-lg border border-slate-200 px-4 py-2 text-sm text-slate-400 dark:border-slate-800">Kirim Email</button>
            @endif
            @if (filled($waTarget))
                <form method="POST" action="{{ route('invoices.send-whatsapp', $invoice) }}" class="inline">
                    @csrf
                    <button class="rounded-lg border border-green-300 px-4 py-2 text-sm text-green-600 hover:bg-green-50 dark:border-green-800 dark:hover:bg-green-950">Kirim WhatsApp</button>
                </form>
            @else
                <button type="button" disabled title="Klien belum punya nomor WhatsApp"
                        class="cursor-not-allowed rounded-lg border border-slate-200 px-4 py-2 text-sm text-slate-400 dark:border-slate-800">Kirim WhatsApp</button>
            @endif

            <form method="POST" action="{{ route('invoices.cancel', $invoice) }}" class="inline" onsubmit="return confirm('Batalkan invoice {{ $invoice->number }}? Tindakan ini tidak dapat dibatalkan.')">
                @csrf
                @method('PATCH')
                <button class="rounded-lg border border-amber-300 px-4 py-2 text-sm text-amber-600 hover:bg-amber-50 dark:border-amber-800 dark:hover:bg-amber-950">Batalkan invoice</button>
            </form>
        @endif

        <a href="{{ route('invoices.pdf', $invoice) }}" class="rounded-lg border border-slate-300 px-4 py-2 text-sm hover:bg-slate-50 dark:border-slate-700 dark:hover:bg-slate-800">Unduh PDF</a>
        <a href="{{ route('invoices.index') }}" class="rounded-lg border border-slate-300 px-4 py-2 text-sm hover:bg-slate-50 dark:border-slate-700 dark:hover:bg-slate-800">Kembali</a>
    </div>

    <!-- Form catat pembayaran (tersembunyi sampai tombol diklik) -->
    @if (! $invoice->isTerminal() && in_array($invoice->status, [InvoiceStatus::Sent, InvoiceStatus::Overdue], true))
        <div id="payment-form" class="hidden rounded-xl border border-green-200 bg-green-50 p-4 dark:border-green-900 dark:bg-green-950/40">
            <form method="POST" action="{{ route('invoices.payments.store', $invoice) }}" class="grid gap-3 sm:grid-cols-2">
                @csrf
                <p class="text-sm font-semibold text-green-800 sm:col-span-2 dark:text-green-200">Catat pembayaran — invoice akan ditandai lunas</p>
                <div>
                    <label class="mb-1 block text-sm font-medium">Nominal (IDR) <span class="text-red-600">*</span></label>
                    <input name="amount" type="number" min="1" step="1" required value="{{ old('amount', $invoice->total) }}" class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm dark:border-slate-700 dark:bg-slate-800">
                    @error('amount')<p class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror
                </div>
                <div>
                    <label class="mb-1 block text-sm font-medium">Metode <span class="text-red-600">*</span></label>
                    <select name="method" required class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm dark:border-slate-700 dark:bg-slate-800">
                        @foreach ($methodLabels as $value => $label)
                            <option value="{{ $value }}" @selected(old('method', 'bank_transfer') === $value)>{{ $label }}</option>
                        @endforeach
                    </select>
                    @error('method')<p class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror
                </div>
                <div>
                    <label class="mb-1 block text-sm font-medium">Tanggal bayar <span class="text-red-600">*</span></label>
                    <input name="paid_at" type="date" required value="{{ old('paid_at', now()->toDateString()) }}" class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm dark:border-slate-700 dark:bg-slate-800">
                    @error('paid_at')<p class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror
                </div>
                <div>
                    <label class="mb-1 block text-sm font-medium">Catatan</label>
                    <input name="note" value="{{ old('note') }}" placeholder="opsional" class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm dark:border-slate-700 dark:bg-slate-800">
                    @error('note')<p class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror
                </div>
                <div class="flex gap-2 sm:col-span-2">
                    <button class="rounded-lg bg-green-600 px-4 py-2 text-sm font-semibold text-white hover:bg-green-700">Simpan pembayaran</button>
                    <button type="button" id="cancel-payment" class="rounded-lg border border-slate-300 px-4 py-2 text-sm hover:bg-slate-50 dark:border-slate-700 dark:hover:bg-slate-800">Tutup</button>
                </div>
            </form>
        </div>
        @if ($errors->any() && old('_token') !== null && $errors->hasAny(['amount', 'method', 'paid_at', 'note']))
            <script>document.getElementById('payment-form').classList.remove('hidden');</script>
        @endif
    @endif

    <!-- Ringkasan -->
    <div class="rounded-xl border border-slate-200 bg-white p-6 dark:border-slate-800 dark:bg-slate-900">
        <div class="mb-4 flex flex-wrap items-center justify-between gap-2">
            <div>
                <p class="text-xs uppercase text-slate-500">Invoice</p>
                <p class="text-xl font-bold">{{ $invoice->number }}</p>
                @if ($invoice->title)<p class="text-sm text-slate-500">{{ $invoice->title }}</p>@endif
            </div>
            <span class="rounded-full px-3 py-1 text-sm font-medium {{ $statusClasses[$invoice->status->value] }}">{{ $invoice->status->label() }}</span>
        </div>

        <dl class="grid gap-x-6 gap-y-4 text-sm sm:grid-cols-2">
            <div><dt class="text-slate-500">Klien</dt><dd class="font-medium"><a href="{{ route('clients.show', $invoice->client) }}" class="text-indigo-600 hover:underline">{{ $invoice->client?->name ?? '—' }}</a></dd></div>
            <div class="sm:col-span-2">
                <dt class="text-slate-500">Layanan terkait ({{ $invoice->services->count() }})</dt>
                <dd class="font-medium">
                    @forelse ($invoice->services as $service)
                        <a href="{{ route('services.show', $service) }}" class="text-indigo-600 hover:underline">{{ $service->name }}</a>@if (! $loop->last), @endif
                    @empty
                        —
                    @endforelse
                </dd>
            </div>
            <div><dt class="text-slate-500">Tanggal terbit</dt><dd class="font-medium">{{ tgl_id($invoice->issue_date) }}</dd></div>
            <div>
                <dt class="text-slate-500">Jatuh tempo</dt>
                <dd class="font-medium">
                    {{ tgl_id($invoice->due_date) }}
                    @if ($isOverdue)
                        <span class="ml-1 rounded-full bg-red-100 px-2 py-0.5 text-xs font-semibold text-red-700 dark:bg-red-900 dark:text-red-200">lewat</span>
                    @endif
                </dd>
            </div>
            @if ($invoice->notes)
                <div class="sm:col-span-2"><dt class="text-slate-500">Catatan</dt><dd class="font-medium whitespace-pre-line">{{ $invoice->notes }}</dd></div>
            @endif
        </dl>
    </div>

    <!-- Termin pembayaran (F4-10) -->
    @if ($invoice->isTermin() && $invoice->parentInvoice)
        <div class="rounded-xl border border-purple-200 bg-purple-50 p-6 dark:border-purple-900 dark:bg-purple-950/40">
            <h2 class="mb-2 text-sm font-semibold uppercase tracking-wide text-purple-800 dark:text-purple-200">Termin dari invoice kontrak</h2>
            <p class="text-sm">
                Invoice ini adalah termin <strong>{{ rtrim(rtrim(number_format((float) $invoice->termin_percent, 2, '.', ''), '0'), '.') }}%</strong>
                dari invoice kontrak
                <a href="{{ route('invoices.show', $invoice->parentInvoice) }}" class="font-semibold text-purple-700 hover:underline dark:text-purple-300">
                    {{ $invoice->parentInvoice->number }}
                </a>
                (nilai kontrak {{ rupiah($invoice->parentInvoice->total) }}).
            </p>
        </div>
    @endif

    @if ($invoice->hasTermins())
        <div class="overflow-x-auto rounded-xl border border-slate-200 bg-white dark:border-slate-800 dark:bg-slate-900">
            <div class="border-b border-slate-200 px-4 py-3 dark:border-slate-800">
                <h2 class="text-sm font-semibold uppercase text-slate-500">Termin pembayaran ({{ $invoice->terminInvoices->count() }})</h2>
                <p class="mt-1 text-xs text-slate-500">
                    Dialokasikan {{ $invoice->allocatedTerminPercent() }}% dari nilai kontrak
                    ({{ rupiah($invoice->allocatedTerminTotal()) }} dari {{ rupiah($invoice->total) }}).
                    @if ($invoice->allTermsPaid())
                        Seluruh termin sudah lunas.
                    @endif
                </p>
            </div>
            <table class="w-full text-sm">
                <thead>
                    <tr class="text-left text-xs uppercase text-slate-500">
                        <th class="px-4 py-3">Termin</th>
                        <th class="px-4 py-3 text-center">Porsi</th>
                        <th class="px-4 py-3">Jatuh tempo</th>
                        <th class="px-4 py-3 text-right">Nominal</th>
                        <th class="px-4 py-3">Status</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($invoice->terminInvoices as $termin)
                        <tr class="border-t border-slate-100 dark:border-slate-800">
                            <td class="px-4 py-3">
                                <a href="{{ route('invoices.show', $termin) }}" class="font-medium text-indigo-600 hover:underline">{{ $termin->number }}</a>
                            </td>
                            <td class="px-4 py-3 text-center tabular-nums">{{ rtrim(rtrim(number_format((float) $termin->termin_percent, 2, '.', ''), '0'), '.') }}%</td>
                            <td class="px-4 py-3">
                                {{ tgl_id($termin->due_date) }}
                                @if ($termin->status === InvoiceStatus::Sent && $termin->due_date->isBefore(today()))
                                    <span class="ml-1 rounded-full bg-red-100 px-2 py-0.5 text-xs font-semibold text-red-700 dark:bg-red-900 dark:text-red-200">lewat</span>
                                @endif
                            </td>
                            <td class="px-4 py-3 text-right tabular-nums">{{ rupiah($termin->total) }}</td>
                            <td class="px-4 py-3">
                                <span class="rounded-full px-2 py-0.5 text-xs font-medium {{ $statusClasses[$termin->status->value] }}">{{ $termin->status->label() }}</span>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
                <tfoot>
                    <tr class="border-t border-slate-200 dark:border-slate-700">
                        <td colspan="3" class="px-4 py-3 text-right font-semibold">Total termin</td>
                        <td class="px-4 py-3 text-right font-bold tabular-nums">{{ rupiah($invoice->allocatedTerminTotal()) }}</td>
                        <td></td>
                    </tr>
                </tfoot>
            </table>
        </div>
    @endif

    <!-- Timeline status -->
    <div class="rounded-xl border border-slate-200 bg-white p-6 dark:border-slate-800 dark:bg-slate-900">
        <h2 class="mb-4 text-sm font-semibold uppercase text-slate-500">Timeline</h2>
        <ol class="space-y-3 text-sm">
            <li class="flex items-center gap-3">
                <span class="flex h-6 w-6 items-center justify-center rounded-full bg-indigo-600 text-xs font-bold text-white">✓</span>
                <span>Dibuat <span class="text-slate-500">({{ $invoice->created_at?->format('d/m/Y H:i') ?? '—' }})</span></span>
            </li>
            <li class="flex items-center gap-3">
                @if ($invoice->sent_at)
                    <span class="flex h-6 w-6 items-center justify-center rounded-full bg-indigo-600 text-xs font-bold text-white">✓</span>
                    <span>Dikirim <span class="text-slate-500">({{ $invoice->sent_at->format('d/m/Y H:i') }})</span></span>
                @else
                    <span class="flex h-6 w-6 items-center justify-center rounded-full border border-slate-300 text-xs text-slate-400 dark:border-slate-600">·</span>
                    <span class="text-slate-400">Dikirim (menunggu)</span>
                @endif
            </li>
            <li class="flex items-center gap-3">
                @if ($invoice->paid_at)
                    <span class="flex h-6 w-6 items-center justify-center rounded-full bg-green-600 text-xs font-bold text-white">✓</span>
                    <span>Lunas <span class="text-slate-500">({{ $invoice->paid_at->format('d/m/Y H:i') }})</span></span>
                @elseif ($invoice->status === InvoiceStatus::Cancelled)
                    <span class="flex h-6 w-6 items-center justify-center rounded-full bg-amber-500 text-xs font-bold text-white">✕</span>
                    <span>Dibatalkan</span>
                @else
                    <span class="flex h-6 w-6 items-center justify-center rounded-full border border-slate-300 text-xs text-slate-400 dark:border-slate-600">·</span>
                    <span class="text-slate-400">Lunas (menunggu)</span>
                @endif
            </li>
        </ol>
    </div>

    <!-- Riwayat pengiriman (F2-5) -->
    <div class="rounded-xl border border-slate-200 bg-white p-6 dark:border-slate-800 dark:bg-slate-900">
        <h2 class="mb-3 text-sm font-semibold uppercase text-slate-500">Riwayat pengiriman</h2>
        @forelse ($deliveries as $log)
            <p class="text-sm text-slate-600 dark:text-slate-300">
                <span class="font-medium">{{ $log->event === 'whatsapp_sent' ? 'WhatsApp' : 'Email' }}</span>
                <span class="text-slate-400">· {{ $log->created_at?->format('d/m/Y H:i') }}</span>
                <span class="block text-slate-500">{{ $log->description }}</span>
            </p>
        @empty
            <p class="text-sm text-slate-400">Belum ada pengiriman.</p>
        @endforelse
    </div>

    <!-- Item -->
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

    <!-- Tautan pembayaran publik (magic link F2-4) -->
    @if ($invoice->status !== InvoiceStatus::Draft)
        <div class="rounded-xl border border-slate-200 bg-white p-6 dark:border-slate-800 dark:bg-slate-900">
            <h2 class="mb-3 text-sm font-semibold uppercase text-slate-500">Tautan pembayaran publik</h2>
            @if ($invoice->public_token)
                <p class="mb-2 text-sm text-slate-500">Bagikan tautan ini ke klien untuk melihat invoice &amp; mengonfirmasi transfer (tanpa login).</p>
                <div class="flex flex-wrap items-center gap-2">
                    <input id="public-link" type="text" readonly value="{{ route('invoices.public.show', ['token' => $invoice->public_token]) }}"
                           class="min-w-0 flex-1 rounded-lg border border-slate-300 px-3 py-2 text-xs dark:border-slate-700 dark:bg-slate-800">
                    <button type="button" id="copy-public-link"
                            class="rounded-lg bg-indigo-600 px-4 py-2 text-sm font-semibold text-white hover:bg-indigo-700">Salin tautan bayar</button>
                    <form method="POST" action="{{ route('invoices.payment-link.revoke', $invoice) }}" class="inline" onsubmit="return confirm('Cabut tautan pembayaran invoice {{ $invoice->number }}? Tautan lama tidak akan bisa diakses lagi.')">
                        @csrf
                        @method('DELETE')
                        <button class="rounded-lg border border-amber-300 px-4 py-2 text-sm text-amber-600 hover:bg-amber-50 dark:border-amber-800 dark:hover:bg-amber-950">Cabut tautan</button>
                    </form>
                </div>
            @else
                <p class="mb-3 text-sm text-slate-500">Klien belum punya tautan pembayaran. Buat tautan baru bila diperlukan.</p>
                <form method="POST" action="{{ route('invoices.payment-link.generate', $invoice) }}" class="inline">
                    @csrf
                    <button class="rounded-lg bg-indigo-600 px-4 py-2 text-sm font-semibold text-white hover:bg-indigo-700">Buat tautan bayar</button>
                </form>
            @endif
        </div>
    @endif

    <!-- Pembayaran -->
    <div class="overflow-x-auto rounded-xl border border-slate-200 bg-white dark:border-slate-800 dark:bg-slate-900">
        <table class="w-full text-sm">
            <thead>
                <tr class="text-left text-xs uppercase text-slate-500">
                    <th class="px-4 py-3">Tanggal</th>
                    <th class="px-4 py-3">Pengirim</th>
                    <th class="px-4 py-3">Metode</th>
                    <th class="px-4 py-3">Status</th>
                    <th class="px-4 py-3">Dicatat oleh</th>
                    <th class="px-4 py-3 text-right">Nominal</th>
                    <th class="px-4 py-3 text-right">Aksi</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($invoice->payments as $payment)
                    <tr class="border-t border-slate-100 dark:border-slate-800">
                        <td class="px-4 py-3">{{ $payment->paid_at?->format('d/m/Y H:i') ?? '—' }}</td>
                        <td class="px-4 py-3">{{ $payment->sender_name ?? '—' }}</td>
                        <td class="px-4 py-3">{{ $methodLabels[$payment->method] ?? $payment->method }}@if ($payment->note)<p class="text-xs text-slate-500">{{ $payment->note }}</p>@endif</td>
                        <td class="px-4 py-3">
                            @php
                                $paymentStatus = match ($payment->status) {
                                    'confirmed' => ['Terkonfirmasi', 'bg-green-100 text-green-700 dark:bg-green-900 dark:text-green-200'],
                                    'rejected' => ['Ditolak', 'bg-red-100 text-red-700 dark:bg-red-900 dark:text-red-200'],
                                    default => ['Menunggu', 'bg-amber-100 text-amber-700 dark:bg-amber-900 dark:text-amber-200'],
                                };
                            @endphp
                            <span class="rounded-full px-2 py-0.5 text-xs font-medium {{ $paymentStatus[1] }}">{{ $paymentStatus[0] }}</span>
                        </td>
                        <td class="px-4 py-3">{{ $payment->confirmer?->name ?? ($payment->status === 'pending' ? 'Menunggu klien' : '—') }}</td>
                        <td class="px-4 py-3 text-right tabular-nums">{{ rupiah($payment->amount) }}</td>
                        <td class="px-4 py-3 text-right">
                            @if ($payment->status === 'pending')
                                <div class="flex justify-end gap-2">
                                    <form method="POST" action="{{ route('invoices.payments.confirm', [$invoice, $payment]) }}" class="inline">
                                        @csrf
                                        @method('PATCH')
                                        <button class="rounded-lg bg-green-600 px-3 py-1.5 text-xs font-semibold text-white hover:bg-green-700">Konfirmasi</button>
                                    </form>
                                    <form method="POST" action="{{ route('invoices.payments.reject', [$invoice, $payment]) }}" class="inline" onsubmit="return confirm('Tolak konfirmasi pembayaran ini?')">
                                        @csrf
                                        @method('PATCH')
                                        <button class="rounded-lg border border-red-300 px-3 py-1.5 text-xs text-red-600 hover:bg-red-50 dark:border-red-800 dark:hover:bg-red-950">Tolak</button>
                                    </form>
                                </div>
                            @else
                                <span class="text-xs text-slate-400">—</span>
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="7" class="px-4 py-6 text-center text-slate-500">Belum ada pembayaran.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>

<script>
    document.getElementById('open-payment')?.addEventListener('click', () => {
        document.getElementById('payment-form').classList.remove('hidden');
        document.getElementById('payment-form').scrollIntoView({ behavior: 'smooth', block: 'center' });
    });
    document.getElementById('cancel-payment')?.addEventListener('click', () => {
        document.getElementById('payment-form').classList.add('hidden');
    });
    document.getElementById('copy-public-link')?.addEventListener('click', async () => {
        const input = document.getElementById('public-link');
        if (!input) return;
        try {
            await navigator.clipboard.writeText(input.value);
        } catch (e) {
            input.select();
            document.execCommand('copy');
        }
        input.select();
    });
</script>
@endsection
