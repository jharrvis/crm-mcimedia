@extends('layouts.app')

@section('title', 'Monitoring Server')

@section('content')
@php
    $metricLabels = [
        'cpu' => 'CPU (%)',
        'ram' => 'RAM (%)',
        'disk' => 'Disk (%)',
        'network' => 'Network (MB/s)',
    ];
    $metricColors = [
        'cpu' => 'rgba(239, 68, 68, 0.8)',
        'ram' => 'rgba(59, 130, 246, 0.8)',
        'disk' => 'rgba(245, 158, 11, 0.8)',
        'network' => 'rgba(16, 185, 129, 0.8)',
    ];
@endphp

<x-page-header title="Monitoring Server" icon="activity"
               subtitle="Grafik utilisasi server real-time dari Netdata (sg2, YIARI, PA Salatiga). Data diambil server-side via tailnet, tidak ada akses Netdata dari browser client. Auto-refresh tiap 30 detik.">
    <span class="text-sm text-slate-400" id="last-updated">Memuat...</span>
</x-page-header>

{{-- Status Server --}}
<x-card class="mb-6">
    <x-table>
        <thead><tr>
            <th>Server</th>
            <th>Host</th>
            <th>Status</th>
            <th>Last Check</th>
        </tr></thead>
        <tbody id="server-status-table">
            @foreach ($servers as $key => $server)
                <tr data-server="{{ $key }}">
                    <td class="font-semibold">{{ $server['name'] }}</td>
                    <td class="font-mono text-xs">{{ $server['host'] }}:{{ $server['port'] }}</td>
                    <td>
                        <span class="inline-flex items-center gap-1.5 rounded-full bg-slate-100 px-2 py-1 text-xs font-semibold text-slate-500 dark:bg-slate-800 dark:text-slate-300" id="status-{{ $key }}">
                            <span class="h-1.5 w-1.5 rounded-full bg-slate-400"></span>
                            Memeriksa...
                        </span>
                    </td>
                    <td class="text-slate-400" id="last-check-{{ $key }}">-</td>
                </tr>
            @endforeach
        </tbody>
    </x-table>
</x-card>

{{-- Grafik Metrik --}}
<div class="grid gap-6 md:grid-cols-2">
    @foreach (['cpu', 'ram', 'disk', 'network'] as $metric)
        <x-card>
            <x-slot:header>
                <h3 class="font-semibold text-slate-800 dark:text-slate-200">{{ $metricLabels[$metric] }}</h3>
                <select class="metric-period-select ml-auto rounded-lg border border-slate-300 bg-white px-2 py-1 text-xs text-slate-700 focus:border-brand-500 focus:outline-none dark:border-slate-600 dark:bg-slate-800 dark:text-slate-200" data-metric="{{ $metric }}">
                    <option value="3600">1 Jam</option>
                    <option value="7200" selected>2 Jam</option>
                    <option value="21600">6 Jam</option>
                    <option value="86400">24 Jam</option>
                </select>
            </x-slot:header>
            <div style="position: relative; height: 256px;">
                <canvas id="chart-{{ $metric }}"></canvas>
            </div>
            <div class="mt-3 flex flex-wrap gap-2" id="legend-{{ $metric }}"></div>
        </x-card>
    @endforeach
</div>
@endsection

@push('scripts')
<script type="module">
// Chart tersedia global dari app.js (bundled via Vite)

// Konfigurasi default Chart.js
Chart.defaults.font.family = 'inherit';
Chart.defaults.font.size = 11;
Chart.defaults.color = '#64748b';
Chart.defaults.plugins.legend.display = false;
Chart.defaults.elements.point.radius = 0;
Chart.defaults.elements.point.hoverRadius = 4;
Chart.defaults.interaction.mode = 'index';
Chart.defaults.interaction.intersect = false;

const servers = @json($servers);
const metricLabels = @json($metricLabels);
const metricColors = @json($metricColors);

// State
const charts = {};
let refreshInterval = null;
const REFRESH_INTERVAL_MS = 30000; // 30 detik

// Fungsi untuk format timestamp ke HH:MM
function formatTime(ts) {
    const date = new Date(ts * 1000);
    return date.toLocaleTimeString('id-ID', { hour: '2-digit', minute: '2-digit' });
}

// Fungsi untuk format nilai berdasarkan metrik
function formatValue(metric, value) {
    switch (metric) {
        case 'cpu':
        case 'ram':
        case 'disk':
            return value.toFixed(1) + '%';
        case 'network':
            // Netdata network biasanya dalam bytes/s, konversi ke MB/s
            return (value / 1024 / 1024).toFixed(2) + ' MB/s';
        default:
            return value.toFixed(2);
    }
}

// Inisialisasi chart untuk satu metrik
function initChart(metric) {
    const ctx = document.getElementById(`chart-${metric}`).getContext('2d');
    const legendEl = document.getElementById(`legend-${metric}`);
    const periodSelect = document.querySelector(`.metric-period-select[data-metric="${metric}"]`);
    const period = periodSelect ? parseInt(periodSelect.value) : 7200;

    // Hapus chart lama jika ada
    if (charts[metric]) {
        charts[metric].destroy();
    }

    charts[metric] = new Chart(ctx, {
        type: 'line',
        data: {
            labels: [],
            datasets: [],
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            animation: { duration: 300 },
            scales: {
                x: {
                    type: 'time',
                    time: {
                        unit: 'minute',
                        displayFormats: { minute: 'HH:mm' },
                        tooltipFormat: 'HH:mm:ss',
                    },
                    grid: { display: false },
                    ticks: { maxTicksLimit: 10, font: { size: 10 } },
                },
                y: {
                    beginAtZero: true,
                    grid: { color: 'rgba(148,163,184,0.1)' },
                    ticks: {
                        font: { size: 10 },
                        callback: (value) => {
                            if (metric === 'network') return (value / 1024 / 1024).toFixed(1) + ' MB/s';
                            return value + '%';
                        },
                    },
                    max: metric === 'network' ? undefined : 100,
                },
            },
            plugins: {
                tooltip: {
                    backgroundColor: 'rgba(15,23,42,0.9)',
                    titleFont: { size: 11 },
                    bodyFont: { size: 11 },
                    padding: 8,
                    callbacks: {
                        label: (context) => {
                            const serverName = context.dataset.label;
                            const value = formatValue(metric, context.parsed.y);
                            return `${serverName}: ${value}`;
                        },
                    },
                },
            },
        },
    });

    // Event listener untuk period select
    periodSelect?.addEventListener('change', () => fetchAndRender(metric));

    // Render legend
    renderLegend(metric);
}

// Render legend manual (karena legend Chart.js di-disable)
function renderLegend(metric) {
    const legendEl = document.getElementById(`legend-${metric}`);
    if (!legendEl) return;

    const chart = charts[metric];
    if (!chart) return;

    legendEl.innerHTML = '';
    chart.data.datasets.forEach((dataset, i) => {
        const color = dataset.borderColor;
        const label = dataset.label;
        const lastValue = dataset.data[dataset.data.length - 1];
        const valueStr = lastValue ? formatValue(metric, lastValue.y) : 'N/A';

        const item = document.createElement('span');
        item.className = 'flex items-center gap-1.5 text-xs';
        item.innerHTML = `
            <span class="w-3 h-3 rounded" style="background: ${color}"></span>
            <span class="font-medium">${label}</span>
            <span class="text-slate-500">${valueStr}</span>
        `;
        legendEl.appendChild(item);
    });
}

// Fetch data dari API dan render ke chart
async function fetchAndRender(metric) {
    const periodSelect = document.querySelector(`.metric-period-select[data-metric="${metric}"]`);
    const period = periodSelect ? parseInt(periodSelect.value) : 7200;
    const points = Math.min(120, Math.max(30, period / 60)); // ~1 titik per menit

    try {
        const response = await fetch(`/security/monitoring/metrics?after=${period}&points=${points}`, {
            headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
        });

        if (!response.ok) throw new Error(`HTTP ${response.status}`);
        const data = await response.json();

        updateChart(metric, data);
        updateLastUpdated();
    } catch (e) {
        console.error(`Gagal fetch ${metric}:`, e);
    }
}

// Update chart dengan data baru
function updateChart(metric, data) {
    const chart = charts[metric];
    if (!chart) return;

    const newDatasets = [];

    // Urutkan server sesuai definisi
    Object.entries(data).forEach(([key, serverData]) => {
        const serverName = serverData.server_name;
        const metricData = serverData.metrics[metric] || [];

        // Transform ke format Chart.js: { x: timestamp, y: value }
        const points = metricData.map(d => ({ x: d.time * 1000, y: d.value }));

        newDatasets.push({
            label: serverName,
            data: points,
            borderColor: metricColors[metric],
            backgroundColor: metricColors[metric].replace('0.8', '0.1'),
            borderWidth: 2,
            fill: true,
            tension: 0.3,
            // Buat setiap server beda warna sedikit
            borderColor: adjustColor(metric, newDatasets.length),
            backgroundColor: adjustColor(metric, newDatasets.length).replace('0.8', '0.1'),
        });
    });

    chart.data.datasets = newDatasets;
    chart.update('none');
    renderLegend(metric);
}

// Adjust color untuk tiap server (server ke-0=red, ke-1=blue, ke-2=amber)
function adjustColor(metricKey, serverIndex) {
    const colors = {
        'cpu': ['rgba(239, 68, 68, 0.8)', 'rgba(59, 130, 246, 0.8)', 'rgba(245, 158, 11, 0.8)'],
        'ram': ['rgba(239, 68, 68, 0.8)', 'rgba(59, 130, 246, 0.8)', 'rgba(245, 158, 11, 0.8)'],
        'disk': ['rgba(239, 68, 68, 0.8)', 'rgba(59, 130, 246, 0.8)', 'rgba(245, 158, 11, 0.8)'],
        'network': ['rgba(239, 68, 68, 0.8)', 'rgba(59, 130, 246, 0.8)', 'rgba(245, 158, 11, 0.8)'],
    };
    const palette = colors[metricKey];
    if (palette && palette[serverIndex]) return palette[serverIndex];
    return 'rgba(148, 163, 184, 0.8)'; // fallback slate
}

// Update status server
async function updateServerStatus() {
    try {
        const response = await fetch('/security/monitoring/health', {
            headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
        });
        if (!response.ok) throw new Error(`HTTP ${response.status}`);
        const health = await response.json();

        Object.entries(health).forEach(([key, healthy]) => {
            const statusEl = document.getElementById(`status-${key}`);
            const lastCheckEl = document.getElementById(`last-check-${key}`);
            if (!statusEl) return;

            if (healthy) {
                statusEl.innerHTML = '<span class="h-1.5 w-1.5 rounded-full bg-green-500"></span> Online';
                statusEl.className = 'inline-flex items-center gap-1 rounded-full px-2.5 py-0.5 text-xs font-medium bg-green-50 text-green-700 dark:bg-green-950 dark:text-green-400';
            } else {
                statusEl.innerHTML = '<span class="h-1.5 w-1.5 rounded-full bg-red-500"></span> Offline';
                statusEl.className = 'inline-flex items-center gap-1 rounded-full px-2.5 py-0.5 text-xs font-medium bg-red-50 text-red-700 dark:bg-red-950 dark:text-red-400';
            }
            lastCheckEl.textContent = new Date().toLocaleTimeString('id-ID');
        });
    } catch (e) {
        console.error('Gagal health check:', e);
    }
}

function updateLastUpdated() {
    const el = document.getElementById('last-updated');
    if (el) {
        el.textContent = `Terakhir update: ${new Date().toLocaleTimeString('id-ID')}`;
    }
}

// Inisialisasi semua chart
function initAllCharts() {
    ['cpu', 'ram', 'disk', 'network'].forEach(initChart);
    ['cpu', 'ram', 'disk', 'network'].forEach(fetchAndRender);
    updateServerStatus();
}

// Auto-refresh
function startAutoRefresh() {
    stopAutoRefresh();
    refreshInterval = setInterval(() => {
        ['cpu', 'ram', 'disk', 'network'].forEach(fetchAndRender);
        updateServerStatus();
    }, REFRESH_INTERVAL_MS);
}

function stopAutoRefresh() {
    if (refreshInterval) {
        clearInterval(refreshInterval);
        refreshInterval = null;
    }
}

// Mulai saat DOM ready
document.addEventListener('DOMContentLoaded', () => {
    initAllCharts();
    startAutoRefresh();
    updateLastUpdated();
});

// Cleanup saat navigasi keluar (optional, untuk SPA-like behavior)
window.addEventListener('beforeunload', stopAutoRefresh);
</script>
@endpush