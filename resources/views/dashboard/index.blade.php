@extends('layouts.app')

@section('title', 'Dashboard')

@section('content')
<div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
    @php
        $cards = [
            ['label' => 'Klien aktif', 'value' => $stats['clients'], 'href' => route('clients.index')],
            ['label' => 'Layanan aktif', 'value' => $stats['services'], 'href' => route('services.index')],
            ['label' => 'Project berjalan', 'value' => $stats['projects'], 'href' => route('projects.index')],
            ['label' => 'Tugas terbuka', 'value' => $stats['tasks'], 'href' => route('tasks.index')],
        ];
    @endphp
    @foreach ($cards as $card)
        <a href="{{ $card['href'] }}" class="rounded-xl border border-slate-200 bg-white p-5 transition hover:shadow dark:border-slate-800 dark:bg-slate-900">
            <p class="text-sm text-slate-500 dark:text-slate-400">{{ $card['label'] }}</p>
            <p class="mt-1 text-3xl font-bold">{{ $card['value'] }}</p>
        </a>
    @endforeach
</div>

@if ($overdueServices->isNotEmpty())
    <div class="mt-6 rounded-xl border border-red-200 bg-white p-5 dark:border-red-900 dark:bg-slate-900">
        <div class="mb-3 flex items-center justify-between">
            <h2 class="font-bold text-red-700 dark:text-red-300">Sudah lewat jatuh tempo ({{ $overdueServices->count() }})</h2>
            <a href="{{ route('reminders.index') }}" class="text-sm text-brand-600 hover:underline">Lihat semua</a>
        </div>
        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead><tr class="text-left text-xs uppercase text-slate-500">
                    <th class="py-2 pr-4">Klien</th><th class="py-2 pr-4">Layanan</th><th class="py-2 pr-4">Berakhir</th><th class="py-2">Terlambat</th>
                </tr></thead>
                <tbody>
                    @foreach ($overdueServices as $s)
                        <tr class="border-t border-slate-100 dark:border-slate-800">
                            <td class="py-2 pr-4">{{ $s->client?->name ?? '—' }}</td>
                            <td class="py-2 pr-4"><a href="{{ route('services.show', $s) }}" class="text-brand-600 hover:underline">{{ $s->name }}</a></td>
                            <td class="py-2 pr-4">{{ tgl_id($s->end_date) }}</td>
                            <td class="py-2 font-semibold text-red-600">{{ abs($s->daysUntilEnd()) }} hari</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </div>
@endif

<div class="mt-6 grid gap-6 xl:grid-cols-2">
    <div class="rounded-xl border border-slate-200 bg-white p-5 dark:border-slate-800 dark:bg-slate-900">
        <div class="mb-3 flex items-center justify-between">
            <h2 class="font-bold">Layanan jatuh tempo ≤ 30 hari</h2>
            <a href="{{ route('reminders.index') }}" class="text-sm text-brand-600 hover:underline">Lihat semua</a>
        </div>
        @if ($expiringServices->isEmpty())
            <p class="text-sm text-slate-500">Tidak ada layanan yang jatuh tempo dalam 30 hari.</p>
        @else
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead><tr class="text-left text-xs uppercase text-slate-500">
                        <th class="py-2 pr-4">Klien</th><th class="py-2 pr-4">Layanan</th><th class="py-2 pr-4">Berakhir</th><th class="py-2">Sisa</th>
                    </tr></thead>
                    <tbody>
                        @foreach ($expiringServices as $s)
                            <tr class="border-t border-slate-100 dark:border-slate-800">
                                <td class="py-2 pr-4">{{ $s->client?->name ?? '—' }}</td>
                                <td class="py-2 pr-4"><a href="{{ route('services.show', $s) }}" class="text-brand-600 hover:underline">{{ $s->name }}</a></td>
                                <td class="py-2 pr-4">{{ tgl_id($s->end_date) }}</td>
                                <td class="py-2 font-semibold text-amber-600">{{ $s->daysUntilEnd() }} hari</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </div>

    <div class="rounded-xl border border-slate-200 bg-white p-5 dark:border-slate-800 dark:bg-slate-900">
        <div class="mb-3 flex items-center justify-between">
            <h2 class="font-bold">Tugas mendesak</h2>
            <a href="{{ route('tasks.index') }}" class="text-sm text-brand-600 hover:underline">Lihat semua</a>
        </div>
        @if ($urgentTasks->isEmpty())
            <p class="text-sm text-slate-500">Tidak ada tugas mendesak.</p>
        @else
            <ul class="divide-y divide-slate-100 dark:divide-slate-800 text-sm">
                @foreach ($urgentTasks as $t)
                    <li class="flex items-center justify-between gap-3 py-2">
                        <div class="min-w-0">
                            <a href="{{ route('tasks.edit', $t) }}" class="font-medium hover:text-brand-600">{{ $t->title }}</a>
                            <p class="text-xs text-slate-500">
                                {{ $t->client?->name ?? '' }}{{ $t->client && $t->project ? ' · ' : '' }}{{ $t->project?->title ?? '' }}
                            </p>
                        </div>
                        <span class="shrink-0 text-xs font-semibold {{ $t->due_date && $t->due_date->isPast() ? 'text-red-600' : 'text-amber-600' }}">
                            {{ $t->due_date ? tgl_id($t->due_date) : 'Tanpa tenggat' }}
                        </span>
                    </li>
                @endforeach
            </ul>
        @endif
    </div>
</div>

<div class="mt-6 rounded-xl border border-slate-200 bg-white p-5 dark:border-slate-800 dark:bg-slate-900">
    <div class="mb-3 flex items-center justify-between">
        <h2 class="font-bold">Project berjalan</h2>
        <a href="{{ route('projects.index') }}" class="text-sm text-brand-600 hover:underline">Lihat semua</a>
    </div>
    @if ($runningProjects->isEmpty())
        <p class="text-sm text-slate-500">Tidak ada project yang berjalan.</p>
    @else
        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead><tr class="text-left text-xs uppercase text-slate-500">
                    <th class="py-2 pr-4">Project</th><th class="py-2 pr-4">Klien</th><th class="py-2 pr-4">Status</th><th class="py-2 pr-4">Deadline</th><th class="py-2 text-right">Nilai</th>
                </tr></thead>
                <tbody>
                    @foreach ($runningProjects as $p)
                        <tr class="border-t border-slate-100 dark:border-slate-800">
                            <td class="py-2 pr-4"><a href="{{ route('projects.show', $p) }}" class="font-medium text-brand-600 hover:underline">{{ $p->title }}</a></td>
                            <td class="py-2 pr-4">{{ $p->client?->name ?? '—' }}</td>
                            <td class="py-2 pr-4"><span class="rounded-full bg-brand-100 px-2 py-0.5 text-xs font-medium text-brand-700 dark:bg-brand-950 dark:text-brand-300">{{ $p->status->label() }}</span></td>
                            <td class="py-2 pr-4">{{ tgl_id($p->deadline) }}</td>
                            <td class="py-2 text-right">{{ rupiah($p->value) }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif
</div>
@endsection
