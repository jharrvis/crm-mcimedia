@extends('layouts.app')

@section('title', 'Dashboard')

@section('content')
<x-page-header title="Dashboard" subtitle="Ringkasan operasional MCI Media hari ini." />

<div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
    @php
        $cards = [
            ['label' => 'Klien aktif', 'value' => $stats['clients'], 'href' => route('clients.index'), 'icon' => 'users'],
            ['label' => 'Layanan aktif', 'value' => $stats['services'], 'href' => route('services.index'), 'icon' => 'server'],
            ['label' => 'Project berjalan', 'value' => $stats['projects'], 'href' => route('projects.index'), 'icon' => 'folder-kanban'],
            ['label' => 'Tugas terbuka', 'value' => $stats['tasks'], 'href' => route('tasks.index'), 'icon' => 'list-checks'],
        ];
    @endphp
    @foreach ($cards as $card)
        <a href="{{ $card['href'] }}" class="rounded-2xl border border-slate-200 bg-white p-5 shadow-soft transition hover:-translate-y-0.5 hover:shadow-md dark:border-slate-800 dark:bg-slate-900">
            <div class="mb-3 flex h-10 w-10 items-center justify-center rounded-xl bg-brand-50 text-brand-600 dark:bg-brand-950 dark:text-brand-300">
                <i data-lucide="{{ $card['icon'] }}" class="h-5 w-5"></i>
            </div>
            <p class="text-sm text-slate-500 dark:text-slate-400">{{ $card['label'] }}</p>
            <p class="mt-1 text-3xl font-bold">{{ $card['value'] }}</p>
        </a>
    @endforeach
</div>

<div class="mt-6 grid gap-6 xl:grid-cols-3">
    <x-card class="xl:col-span-2">
        <x-slot:header>
            <h2 class="font-bold">Pendapatan (12 bulan)</h2>
        </x-slot:header>
        <div class="h-64"><canvas id="revenueChart"></canvas></div>
    </x-card>

    <x-card>
        <x-slot:header>
            <h2 class="font-bold">Status Invoice</h2>
        </x-slot:header>
        <div class="h-64"><canvas id="invoiceStatusChart"></canvas></div>
    </x-card>
</div>

@if ($overdueServices->isNotEmpty())
    <x-card class="mt-6 border-red-200 dark:border-red-900">
        <x-slot:header>
            <h2 class="font-bold text-red-700 dark:text-red-300">Sudah lewat jatuh tempo ({{ $overdueServices->count() }})</h2>
            <a href="{{ route('reminders.index') }}" class="text-sm text-brand-600 hover:underline">Lihat semua</a>
        </x-slot:header>
        <x-table>
            <thead><tr>
                <th>Klien</th><th>Layanan</th><th>Berakhir</th><th>Terlambat</th>
            </tr></thead>
            <tbody>
                @foreach ($overdueServices as $s)
                    <tr>
                        <td>{{ $s->client?->name ?? '—' }}</td>
                        <td><a href="{{ route('services.show', $s) }}" class="text-brand-600 hover:underline">{{ $s->name }}</a></td>
                        <td>{{ tgl_id($s->end_date) }}</td>
                        <td class="font-semibold text-red-600">{{ abs($s->daysUntilEnd()) }} hari</td>
                    </tr>
                @endforeach
            </tbody>
        </x-table>
    </x-card>
@endif

<div class="mt-6 grid gap-6 xl:grid-cols-2">
    <x-card>
        <x-slot:header>
            <h2 class="font-bold">Layanan jatuh tempo ≤ 30 hari</h2>
            <a href="{{ route('reminders.index') }}" class="text-sm text-brand-600 hover:underline">Lihat semua</a>
        </x-slot:header>
        @if ($expiringServices->isEmpty())
            <x-empty-state title="Tidak ada layanan jatuh tempo" description="Tidak ada layanan yang jatuh tempo dalam 30 hari." />
        @else
            <x-table>
                <thead><tr>
                    <th>Klien</th><th>Layanan</th><th>Berakhir</th><th>Sisa</th>
                </tr></thead>
                <tbody>
                    @foreach ($expiringServices as $s)
                        <tr>
                            <td>{{ $s->client?->name ?? '—' }}</td>
                            <td><a href="{{ route('services.show', $s) }}" class="text-brand-600 hover:underline">{{ $s->name }}</a></td>
                            <td>{{ tgl_id($s->end_date) }}</td>
                            <td class="font-semibold text-amber-600">{{ $s->daysUntilEnd() }} hari</td>
                        </tr>
                    @endforeach
                </tbody>
            </x-table>
        @endif
    </x-card>

    <x-card>
        <x-slot:header>
            <h2 class="font-bold">Tugas mendesak</h2>
            <a href="{{ route('tasks.index') }}" class="text-sm text-brand-600 hover:underline">Lihat semua</a>
        </x-slot:header>
        @if ($urgentTasks->isEmpty())
            <x-empty-state title="Tidak ada tugas mendesak" description="Semua tugas berjalan sesuai rencana." />
        @else
            <ul class="divide-y divide-slate-100 text-sm dark:divide-slate-800">
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
    </x-card>
</div>

<x-card class="mt-6">
    <x-slot:header>
        <h2 class="font-bold">Project berjalan</h2>
        <a href="{{ route('projects.index') }}" class="text-sm text-brand-600 hover:underline">Lihat semua</a>
    </x-slot:header>
    @if ($runningProjects->isEmpty())
        <x-empty-state title="Tidak ada project berjalan" description="Belum ada project dengan status berjalan." />
    @else
        <x-table>
            <thead><tr>
                <th>Project</th><th>Klien</th><th>Status</th><th>Deadline</th><th class="text-right">Nilai</th>
            </tr></thead>
            <tbody>
                @foreach ($runningProjects as $p)
                    <tr>
                        <td><a href="{{ route('projects.show', $p) }}" class="font-medium text-brand-600 hover:underline">{{ $p->title }}</a></td>
                        <td>{{ $p->client?->name ?? '—' }}</td>
                        <td><x-badge variant="info" :dot="true">{{ $p->status->label() }}</x-badge></td>
                        <td>{{ tgl_id($p->deadline) }}</td>
                        <td class="text-right">{{ rupiah($p->value) }}</td>
                    </tr>
                @endforeach
            </tbody>
        </x-table>
    @endif
</x-card>
@endsection

@push('scripts')
<script type="module">
Chart.defaults.font.family = 'inherit';
Chart.defaults.font.size = 11;
Chart.defaults.color = '#64748b';
Chart.defaults.plugins.legend.display = false;
Chart.defaults.elements.point.radius = 0;
Chart.defaults.elements.point.hoverRadius = 4;
Chart.defaults.interaction.mode = 'index';
Chart.defaults.interaction.intersect = false;

new Chart(document.getElementById('revenueChart'), {
    type: 'line',
    data: {
        labels: @json($revenueChart['labels']),
        datasets: [{
            label: 'Pendapatan',
            data: @json($revenueChart['data']),
            borderColor: '#2563eb',
            backgroundColor: 'rgba(37, 99, 235, 0.08)',
            fill: true,
            tension: 0.35,
        }],
    },
    options: {
        responsive: true,
        maintainAspectRatio: false,
        plugins: { tooltip: { callbacks: { label: (ctx) => ' Rp ' + ctx.parsed.y.toLocaleString('id-ID') } } },
        scales: { y: { ticks: { callback: (v) => 'Rp ' + (v / 1e6) + ' jt' } } },
    },
});

new Chart(document.getElementById('invoiceStatusChart'), {
    type: 'doughnut',
    data: {
        labels: @json($invoiceStatusChart['labels']),
        datasets: [{
            data: @json($invoiceStatusChart['data']),
            backgroundColor: ['#94a3b8', '#3b82f6', '#10b981', '#f59e0b', '#ef4444'],
            borderWidth: 0,
        }],
    },
    options: {
        responsive: true,
        maintainAspectRatio: false,
        cutout: '65%',
        plugins: { legend: { display: true, position: 'bottom' } },
    },
});
</script>
@endpush
