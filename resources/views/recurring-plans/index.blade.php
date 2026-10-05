@extends('layouts.app')

@section('title', 'Paket Recurring')

@section('content')
@php
    $clientOptions = collect($clients)->mapWithKeys(fn ($c) => [$c->id => $c->name])->prepend('', 'Semua klien')->all();
    $cycleOptions = ['all' => 'Semua siklus'] + collect($cycles)->mapWithKeys(fn ($c) => [$c->value => $c->label()])->all();
    $statusOptions = ['all' => 'Semua status', 'active' => 'Aktif', 'inactive' => 'Nonaktif'];
@endphp

<x-page-header title="Paket Recurring" icon="repeat"
               subtitle="Invoice terbit otomatis per siklus: bulanan, 3, 6, atau 12 bulan.">
    <x-btn :href="route('recurring-plans.create')" icon="plus">Buat paket</x-btn>
</x-page-header>

<x-card>
    <form method="GET" action="{{ route('recurring-plans.index') }}" class="mb-4 grid gap-2 sm:grid-cols-2 lg:grid-cols-5">
        <x-input name="q" placeholder="Cari judul / klien…" :value="request('q')" inputId="rp-q" />
        <x-input name="client_id" type="select" :options="$clientOptions" :value="(string) request('client_id')" inputId="rp-client" />
        <x-input name="cycle" type="select" :options="$cycleOptions" :value="request('cycle', 'all')" inputId="rp-cycle" />
        <x-input name="status" type="select" :options="$statusOptions" :value="request('status', 'all')" inputId="rp-status" />
        <div class="flex items-center gap-2">
            <x-btn type="submit" variant="dark" icon="search">Filter</x-btn>
            @if (request()->hasAny(['q', 'client_id', 'cycle', 'status']))
                <x-btn :href="route('recurring-plans.index')" variant="ghost">Reset</x-btn>
            @endif
        </div>
    </form>

    <x-table>
        <thead><tr>
            <th>Paket</th>
            <th>Klien</th>
            <th>Siklus</th>
            <th>Tagihan berikutnya</th>
            <th class="text-right">Total / periode</th>
            <th>Status</th>
            <th class="text-right">Aksi</th>
        </tr></thead>
        <tbody>
            @forelse ($plans as $plan)
                @php
                    $days = $plan->daysUntilNext();
                    $due = $plan->active && $days <= 0;
                @endphp
                <tr class="{{ $due ? 'bg-amber-50 dark:bg-amber-950/30' : '' }}">
                    <td>
                        <a href="{{ route('recurring-plans.show', $plan) }}" class="font-semibold text-brand-600 hover:underline">{{ $plan->title }}</a>
                        @if ($plan->service)
                            <p class="text-xs text-slate-400">{{ $plan->service->name }}</p>
                        @endif
                    </td>
                    <td><a href="{{ route('clients.show', $plan->client) }}" class="hover:text-brand-600">{{ $plan->client?->name ?? '—' }}</a></td>
                    <td>{{ $plan->cycle->label() }}</td>
                    <td class="whitespace-nowrap">
                        {{ tgl_id($plan->next_invoice_date) }}
                        @if ($plan->active)
                            @if ($days < 0)
                                <x-badge variant="danger" class="ml-1">{{ abs($days) }} hari lewat</x-badge>
                            @elseif ($days === 0)
                                <x-badge variant="warning" class="ml-1">jatuh tempo hari ini</x-badge>
                            @elseif ($days <= 7)
                                <x-badge variant="warning" class="ml-1">sisa {{ $days }} hari</x-badge>
                            @endif
                        @endif
                    </td>
                    <td class="text-right tabular-nums">{{ rupiah($plan->total) }}</td>
                    <td>
                        @if ($plan->active)
                            <x-badge variant="success" :dot="true">Aktif</x-badge>
                        @else
                            <x-badge variant="slate">Nonaktif</x-badge>
                        @endif
                    </td>
                    <td class="whitespace-nowrap text-right">
                        <a href="{{ route('recurring-plans.show', $plan) }}" class="text-sm font-semibold text-brand-600 hover:underline">Detail</a>
                    </td>
                </tr>
            @empty
                <tr><td colspan="7">
                    <x-empty-state title="Belum ada paket recurring" icon="repeat"
                                   description="Buat paket pertama agar invoice terbit otomatis per siklus.">
                        <x-slot:action>
                            <x-btn :href="route('recurring-plans.create')" icon="plus">Buat paket</x-btn>
                        </x-slot:action>
                    </x-empty-state>
                </td></tr>
            @endforelse
        </tbody>
    </x-table>

    @if ($plans->hasPages())
        <div class="mt-4">{{ $plans->links() }}</div>
    @endif
</x-card>
@endsection
