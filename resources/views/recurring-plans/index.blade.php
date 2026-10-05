@extends('layouts.app')

@section('title', 'Paket Recurring')

@section('content')
<div class="mb-4 flex flex-wrap items-center gap-2">
    <a href="{{ route('recurring-plans.create') }}" class="rounded-lg bg-brand-600 px-4 py-2 text-sm font-semibold text-white hover:bg-brand-700">Buat paket</a>
    <span class="text-sm text-slate-500">Invoice terbit otomatis per siklus: bulanan, 3, 6, atau 12 bulan.</span>
</div>

<form method="GET" action="{{ route('recurring-plans.index') }}" class="mb-4 grid gap-2 rounded-xl border border-slate-200 bg-white p-4 sm:grid-cols-5 dark:border-slate-800 dark:bg-slate-900">
    <input name="q" value="{{ request('q') }}" placeholder="Cari judul / klien…" class="rounded-lg border border-slate-300 px-3 py-2 text-sm dark:border-slate-700 dark:bg-slate-800">
    <select name="client_id" class="rounded-lg border border-slate-300 px-3 py-2 text-sm dark:border-slate-700 dark:bg-slate-800">
        <option value="">Semua klien</option>
        @foreach ($clients as $c)
            <option value="{{ $c->id }}" @selected(request('client_id') == $c->id)>{{ $c->name }}</option>
        @endforeach
    </select>
    <select name="cycle" class="rounded-lg border border-slate-300 px-3 py-2 text-sm dark:border-slate-700 dark:bg-slate-800">
        <option value="all" @selected(request('cycle') === 'all')>Semua siklus</option>
        @foreach ($cycles as $c)
            <option value="{{ $c->value }}" @selected(request('cycle') === $c->value)>{{ $c->label() }}</option>
        @endforeach
    </select>
    <select name="status" class="rounded-lg border border-slate-300 px-3 py-2 text-sm dark:border-slate-700 dark:bg-slate-800">
        <option value="all" @selected(request('status') === 'all')>Semua status</option>
        <option value="active" @selected(request('status') === 'active')>Aktif</option>
        <option value="inactive" @selected(request('status') === 'inactive')>Nonaktif</option>
    </select>
    <button class="rounded-lg bg-slate-800 px-4 py-2 text-sm font-semibold text-white hover:bg-slate-700 dark:bg-slate-700">Filter</button>
</form>

<div class="overflow-x-auto rounded-xl border border-slate-200 bg-white dark:border-slate-800 dark:bg-slate-900">
    <table class="w-full text-sm">
        <thead>
            <tr class="text-left text-xs uppercase text-slate-500">
                <th class="px-4 py-3">Paket</th>
                <th class="px-4 py-3">Klien</th>
                <th class="px-4 py-3">Siklus</th>
                <th class="px-4 py-3">Tagihan berikutnya</th>
                <th class="px-4 py-3 text-right">Total / periode</th>
                <th class="px-4 py-3">Status</th>
                <th class="px-4 py-3 text-right">Aksi</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($plans as $plan)
                @php
                    $days = $plan->daysUntilNext();
                    $due = $plan->active && $days <= 0;
                @endphp
                <tr class="border-t border-slate-100 dark:border-slate-800 {{ $due ? 'bg-amber-50 dark:bg-amber-950/30' : '' }}">
                    <td class="px-4 py-3">
                        <a href="{{ route('recurring-plans.show', $plan) }}" class="font-medium text-brand-600 hover:underline">{{ $plan->title }}</a>
                        @if ($plan->service)
                            <p class="text-xs text-slate-500">{{ $plan->service->name }}</p>
                        @endif
                    </td>
                    <td class="px-4 py-3"><a href="{{ route('clients.show', $plan->client) }}" class="hover:text-brand-600">{{ $plan->client?->name ?? '—' }}</a></td>
                    <td class="px-4 py-3">{{ $plan->cycle->label() }}</td>
                    <td class="px-4 py-3">
                        {{ tgl_id($plan->next_invoice_date) }}
                        @if ($plan->active)
                            @if ($days < 0)
                                <span class="ml-1 rounded-full bg-red-100 px-2 py-0.5 text-xs font-semibold text-red-700 dark:bg-red-900 dark:text-red-200">{{ abs($days) }} hari lewat</span>
                            @elseif ($days === 0)
                                <span class="ml-1 rounded-full bg-amber-100 px-2 py-0.5 text-xs font-semibold text-amber-700 dark:bg-amber-900 dark:text-amber-200">jatuh tempo hari ini</span>
                            @elseif ($days <= 7)
                                <span class="ml-1 rounded-full bg-amber-100 px-2 py-0.5 text-xs font-semibold text-amber-700 dark:bg-amber-900 dark:text-amber-200">sisa {{ $days }} hari</span>
                            @endif
                        @endif
                    </td>
                    <td class="px-4 py-3 text-right tabular-nums">{{ rupiah($plan->total) }}</td>
                    <td class="px-4 py-3">
                        @if ($plan->active)
                            <span class="rounded-full bg-green-100 px-2 py-0.5 text-xs font-semibold text-green-700 dark:bg-green-900 dark:text-green-200">Aktif</span>
                        @else
                            <span class="rounded-full bg-slate-200 px-2 py-0.5 text-xs font-semibold text-slate-600 dark:bg-slate-700 dark:text-slate-300">Nonaktif</span>
                        @endif
                    </td>
                    <td class="px-4 py-3 text-right">
                        <a href="{{ route('recurring-plans.show', $plan) }}" class="text-brand-600 hover:underline">Detail</a>
                    </td>
                </tr>
            @empty
                <tr>
                    <td colspan="7" class="px-4 py-6 text-center text-sm text-slate-500">
                        Belum ada paket recurring.
                        <a href="{{ route('recurring-plans.create') }}" class="text-brand-600 hover:underline">Buat paket pertama</a>
                        agar invoice terbit otomatis per siklus.
                    </td>
                </tr>
            @endforelse
        </tbody>
    </table>
</div>

<div class="mt-4">{{ $plans->links() }}</div>
@endsection