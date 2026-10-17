@extends('layouts.public')

@section('title', 'Penawaran '.$quotation->number)

@section('content')
@php
    use App\Domains\Quotation\Enums\QuotationStatus;

    $statusClasses = [
        'draft' => 'bg-slate-200 text-slate-600',
        'sent' => 'bg-blue-100 text-blue-700',
        'accepted' => 'bg-green-100 text-green-700',
        'rejected' => 'bg-red-100 text-red-700',
        'expired' => 'bg-amber-100 text-amber-700',
        'converted' => 'bg-purple-100 text-purple-700',
    ];
@endphp

<div class="space-y-4">

    <!-- Kop identitas usaha + nomor penawaran -->
    <div class="rounded-xl border border-slate-200 bg-white p-6 dark:border-slate-800 dark:bg-slate-900">
        <div class="flex flex-wrap items-start justify-between gap-4">
            <div>
                <p class="text-lg font-bold text-indigo-600">{{ $business['name'] }}</p>
                <p class="text-xs text-slate-500">{{ $business['address'] }}</p>
                <p class="text-xs text-slate-500">{{ $business['email'] }} · {{ $business['phone'] }}</p>
            </div>
            <div class="text-right">
                <p class="text-xs uppercase tracking-wide text-slate-500">Penawaran</p>
                <p class="text-xl font-bold">{{ $quotation->number }}</p>
                <span class="mt-1 inline-block rounded-full px-3 py-1 text-sm font-semibold {{ $statusClasses[$quotation->status->value] ?? 'bg-slate-200 text-slate-600' }}">
                    {{ $quotation->status->label() }}
                </span>
            </div>
        </div>

        <dl class="mt-6 grid gap-x-6 gap-y-4 text-sm sm:grid-cols-3">
            <div><dt class="text-slate-500">Ditagihkan kepada</dt><dd class="font-medium">{{ $quotation->client?->name ?? '—' }}</dd></div>
            <div><dt class="text-slate-500">Tanggal terbit</dt><dd class="font-medium">{{ tgl_id($quotation->issue_date) }}</dd></div>
            <div>
                <dt class="text-slate-500">Masa berlaku</dt>
                <dd class="font-medium">
                    {{ tgl_id($quotation->valid_until) }}
                    @if ($quotation->status === QuotationStatus::Expired)
                        <span class="ml-1 rounded-full bg-amber-100 px-2 py-0.5 text-xs font-semibold text-amber-700">kedaluwarsa</span>
                    @endif
                </dd>
            </div>
        </dl>

        @if ($quotation->title)
            <p class="mt-4 text-sm text-slate-500">Perihal: <span class="font-medium text-slate-700 dark:text-slate-200">{{ $quotation->title }}</span></p>
        @endif

        @if ($quotation->status === QuotationStatus::Accepted)
            <div class="mt-4 rounded-lg border border-green-200 bg-green-50 px-4 py-3 text-sm font-semibold text-green-800">
                TERIMA KASIH — Penawaran ini telah Anda setujui. Tim kami akan menghubungi Anda untuk tindak lanjut.
            </div>
        @elseif ($quotation->status === QuotationStatus::Rejected)
            <div class="mt-4 rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm font-semibold text-red-800">
                Penawaran ini telah Anda tolak. Terima kasih atas tanggapan Anda.
            </div>
        @elseif ($quotation->status === QuotationStatus::Expired)
            <div class="mt-4 rounded-lg border border-amber-200 bg-amber-50 px-4 py-3 text-sm font-semibold text-amber-800">
                Penawaran ini sudah melewati masa berlaku. Silakan hubungi kami untuk perpanjangan.
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
                    <th class="px-4 py-3 text-right whitespace-nowrap">Harga satuan</th>
                    <th class="px-4 py-3 text-right whitespace-nowrap">Jumlah</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($quotation->items as $item)
                    <tr class="border-t border-slate-100 dark:border-slate-800">
                        <td class="px-4 py-3">{{ $item->description }}</td>
                        <td class="px-4 py-3 text-center">{{ $item->quantity }}</td>
                        <td class="px-4 py-3 text-right tabular-nums whitespace-nowrap">{{ rupiah($item->unit_price) }}</td>
                        <td class="px-4 py-3 text-right tabular-nums whitespace-nowrap">{{ rupiah($item->amount) }}</td>
                    </tr>
                @empty
                    <tr><td colspan="4" class="px-4 py-6 text-center text-slate-500">Belum ada item.</td></tr>
                @endforelse
            </tbody>
            <tfoot>
                @if ($quotation->discount > 0)
                    <tr class="border-t border-slate-200 dark:border-slate-700">
                        <td colspan="3" class="px-4 py-3 text-right font-semibold">Diskon</td>
                        <td class="px-4 py-3 text-right font-semibold tabular-nums whitespace-nowrap text-red-600">-{{ rupiah($quotation->discount) }}</td>
                    </tr>
                @endif
                <tr class="border-t border-slate-200 dark:border-slate-700">
                    <td colspan="3" class="px-4 py-3 text-right font-semibold">Total</td>
                    <td class="px-4 py-3 text-right font-bold tabular-nums whitespace-nowrap">{{ rupiah($quotation->total) }}</td>
                </tr>
            </tfoot>
        </table>
    </div>

    <!-- Catatan & syarat -->
    @if ($quotation->notes || $quotation->terms)
        <div class="rounded-xl border border-slate-200 bg-white p-6 dark:border-slate-800 dark:bg-slate-900">
            @if ($quotation->notes)
                <div class="mb-4">
                    <h2 class="mb-2 text-sm font-semibold uppercase tracking-wide text-slate-500">Catatan</h2>
                    <p class="text-sm whitespace-pre-line text-slate-700 dark:text-slate-200">{{ $quotation->notes }}</p>
                </div>
            @endif
            @if ($quotation->terms)
                <div>
                    <h2 class="mb-2 text-sm font-semibold uppercase tracking-wide text-slate-500">Syarat &amp; Ketentuan</h2>
                    <p class="text-sm whitespace-pre-line text-slate-700 dark:text-slate-200">{{ $quotation->terms }}</p>
                </div>
            @endif
        </div>
    @endif

    <!-- Aksi setuju / tolak -->
    @if ($canBeAnswered)
        <div class="rounded-xl border border-slate-200 bg-white p-6 dark:border-slate-800 dark:bg-slate-900">
            <p class="mb-4 text-sm text-slate-600 dark:text-slate-300">
                Apakah Anda menyetujui penawaran ini? Mohon pilih salah satu:
            </p>
            <div class="flex gap-3">
                <form method="POST" action="{{ route('quotations.public.accept', ['token' => $quotation->public_token]) }}" class="flex-1">
                    @csrf
                    <button type="submit"
                            class="w-full rounded-lg bg-green-600 px-4 py-3 text-sm font-semibold text-white hover:bg-green-700">
                        Setuju
                    </button>
                </form>
                <form method="POST" action="{{ route('quotations.public.reject', ['token' => $quotation->public_token]) }}"
                      class="flex-1"
                      onsubmit="return confirm('Anda yakin ingin menolak penawaran ini?')">
                    @csrf
                    <button type="submit"
                            class="w-full rounded-lg border border-red-300 px-4 py-3 text-sm font-semibold text-red-600 hover:bg-red-50 dark:border-red-800 dark:hover:bg-red-950">
                        Tolak
                    </button>
                </form>
            </div>
        </div>
    @endif

    <!-- Unduh PDF -->
    <div>
        <a href="{{ route('quotations.public.pdf', ['token' => $quotation->public_token]) }}"
           class="inline-block rounded-lg border border-slate-300 px-4 py-2 text-sm hover:bg-slate-50 dark:border-slate-700 dark:hover:bg-slate-800">
            Unduh PDF penawaran
        </a>
    </div>
</div>
@endsection
