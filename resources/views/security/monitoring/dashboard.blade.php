@extends('layouts.app')

@section('title', 'Security Monitoring Dashboard')

@section('content')
@php
    $severityClass = fn (\App\Domains\Security\Enums\IncidentSeverity $s) => $s->badgeClass();
    $severityLabel = fn (\App\Domains\Security\Enums\IncidentSeverity $s) => $s->label();
@endphp

<div class="mb-4 flex flex-wrap items-center gap-2">
    <h2 class="text-xl font-bold">Security Monitoring Dashboard</h2>
    <span class="ml-auto text-sm text-slate-500" id="last-updated">Memuat...</span>
</div>

<p class="mb-4 text-sm text-slate-500">
    Dashboard keamanan terpusat: ringkasan status, grafik traffic, log serangan, dan status layanan.
    Data realtime dari script aggregator (auto-refresh tiap 30 detik).
</p>

<!-- 1. RINGKASAN STATUS (Kartu Angka) -->
<div class="mb-6 grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
    <!-- Insiden Open Critical -->
    <div class="rounded-xl border border-slate-200 bg-white p-4 dark:border-slate-800 dark:bg-slate-900">
        <div class="flex items-center justify-between">
            <div>
                <p class="text-xs uppercase text-slate-500">Insiden Kritis (P1)</p>
                <p class="mt-1 text-3xl font-bold text-red-600 dark:text-red-400" id="stat-critical">-</p>
            </div>
            <div class="p-3 rounded-full bg-red-100 text-red-600 dark:bg-red-900 dark:text-red-400">
                <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"></path></svg>
            </div>
        </div>
    </div>

    <!-- Insiden Open High -->
    <div class="rounded-xl border border-slate-200 bg-white p-4 dark:border-slate-800 dark:bg-slate-900">
        <div class="flex items-center justify-between">
            <div>
                <p class="text-xs uppercase text-slate-500">Insiden Tinggi (P2)</p>
                <p class="mt-1 text-3xl font-bold text-orange-600 dark:text-orange-400" id="stat-high">-</p>
            </div>
            <div class="p-3 rounded-full bg-orange-100 text-orange-600 dark:bg-orange-900 dark:text-orange-400">
                <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"></path></svg>
            </div>
        </div>
    </div>

    <!-- Insiden Open Medium -->
    <div class="rounded-xl border border-slate-200 bg-white p-4 dark:border-slate-800 dark:bg-slate-900">
        <div class="flex items-center justify-between">
            <div>
                <p class="text-xs uppercase text-slate-500">Insiden Sedang (P3)</p>
                <p class="mt-1 text-3xl font-bold text-amber-600 dark:text-amber-400" id="stat-medium">-</p>
            </div>
            <div class="p-3 rounded-full bg-amber-100 text-amber-600 dark:bg-amber-900 dark:text-amber-400">
                <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path></svg>
            </div>
        </div>
    </div>

    <!-- WAF Blocked Today -->
    <div class="rounded-xl border border-slate-200 bg-white p-4 dark:border-slate-800 dark:bg-slate-900">
        <div class="flex items-center justify-between">
            <div>
                <p class="text-xs uppercase text-slate-500">WAF Blocked Hari Ini</p>
                <p class="mt-1 text-3xl font-bold text-brand-600 dark:text-brand-400" id="stat-waf">-</p>
            </div>
            <div class="p-3 rounded-full bg-brand-100 text-brand-600 dark:bg-brand-900 dark:text-brand-400">
                <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z"></path></svg>
            </div>
        </div>
    </div>

    <!-- Failed Logins 24h -->
    <div class="rounded-xl border border-slate-200 bg-white p-4 dark:border-slate-800 dark:bg-slate-900">
        <div class="flex items-center justify-between">
            <div>
                <p class="text-xs uppercase text-slate-500">Failed Logins 24h</p>
                <p class="mt-1 text-3xl font-bold text-pink-600 dark:text-pink-400" id="stat-failed-logins">-</p>
            </div>
            <div class="p-3 rounded-full bg-pink-100 text-pink-600 dark:bg-pink-900 dark:text-pink-400">
                <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 7a2 2 0 012 2m4 0a6 6 0 01-7.743 5.743L11 17H9v2H7v2H4a1 1 0 01-1-1v-2.586a1 1 0 01.293-.707l5.964-5.964A6 6 0 1121 9z"></path></svg>
            </div>
        </div>
    </div>

    <!-- SSL Expiring < 14d -->
    <div class="rounded-xl border border-slate-200 bg-white p-4 dark:border-slate-800 dark:bg-slate-900">
        <div class="flex items-center justify-between">
            <div>
                <p class="text-xs uppercase text-slate-500">SSL Expired < 14 Hari</p>
                <p class="mt-1 text-3xl font-bold text-emerald-600 dark:text-emerald-400" id="stat-ssl">-</p>
            </div>
            <div class="p-3 rounded-full bg-emerald-100 text-emerald-600 dark:bg-emerald-900 dark:text-emerald-400">
                <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z"></path></svg>
            </div>
        </div>
    </div>

    <!-- Total Open Incidents -->
    <div class="rounded-xl border border-slate-200 bg-white p-4 dark:border-slate-800 dark:bg-slate-900">
        <div class="flex items-center justify-between">
            <div>
                <p class="text-xs uppercase text-slate-500">Total Insiden Open</p>
                <p class="mt-1 text-3xl font-bold text-slate-700 dark:text-slate-300" id="stat-total-open">-</p>
            </div>
            <div class="p-3 rounded-full bg-slate-100 text-slate-600 dark:bg-slate-700 dark:text-slate-400">
                <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"></path></svg>
            </div>
        </div>
    </div>
</div>

<!-- 2. GRAFIK TRAFFIC -->
<div class="mb-6 rounded-xl border border-slate-200 bg-white p-4 dark:border-slate-800 dark:bg-slate-900">
    <div class="mb-4 flex flex-wrap items-center justify-between gap-2">
        <h3 class="font-semibold text-slate-800 dark:text-slate-200">Traffic Requests/detik per Site</h3>
        <div class="flex items-center gap-2">
            <label class="text-xs text-slate-500">Time Range:</label>
            <select id="traffic-range" class="rounded-lg border border-slate-300 px-2 py-1 text-xs dark:border-slate-600 dark:bg-slate-800">
                <option value="1h">1 Jam</option>
                <option value="24h" selected>24 Jam</option>
                <option value="7d">7 Hari</option>
            </select>
        </div>
    </div>
    <div class="h-80" style="position: relative; height: 320px;">
        <canvas id="traffic-chart"></canvas>
    </div>
    <div class="mt-3 flex flex-wrap gap-2" id="traffic-legend"></div>
</div>

<!-- 3. LOG SERANGAN TERKINI -->
<div class="mb-6 rounded-xl border border-slate-200 bg-white dark:border-slate-800 dark:bg-slate-900">
    <div class="mb-3 flex flex-wrap items-center justify-between gap-2 border-b border-slate-200 pb-3 dark:border-slate-700">
        <h3 class="font-semibold text-slate-800 dark:text-slate-200">Log Serangan Terkini</h3>
        <div class="flex items-center gap-2">
            <span class="text-xs text-slate-500" id="attacks-count">0 entri</span>
            <button id="refresh-attacks" class="rounded-lg border border-slate-300 px-3 py-1.5 text-xs font-medium hover:bg-slate-50 dark:border-slate-700 dark:hover:bg-slate-800">Refresh</button>
        </div>
    </div>
    <div class="overflow-x-auto">
        <table class="w-full text-sm">
            <thead>
                <tr class="text-left text-xs uppercase text-slate-500">
                    <th class="px-4 py-3">Waktu</th>
                    <th class="px-4 py-3">IP</th>
                    <th class="px-4 py-3">Negara</th>
                    <th class="px-4 py-3">Tipe</th>
                    <th class="px-4 py-3">Target URL</th>
                    <th class="px-4 py-3">Severity</th>
                </tr>
            </thead>
            <tbody id="attacks-table">
                <tr>
                    <td colspan="6" class="px-4 py-8 text-center text-slate-500">Memuat...</td>
                </tr>
            </tbody>
        </table>
    </div>
    <div class="mt-3 flex items-center justify-between border-t border-slate-200 pt-3 dark:border-slate-700" id="attacks-pagination">
        <span class="text-xs text-slate-500" id="attacks-pagination-info"></span>
        <div class="flex gap-1">
            <button id="attacks-prev" class="rounded-lg border border-slate-300 px-2 py-1 text-xs disabled:opacity-50 disabled:cursor-not-allowed dark:border-slate-700">Sebelumnya</button>
            <button id="attacks-next" class="rounded-lg border border-slate-300 px-2 py-1 text-xs disabled:opacity-50 disabled:cursor-not-allowed dark:border-slate-700">Selanjutnya</button>
        </div>
    </div>
</div>

<!-- 4. STATUS LAYANAN -->
<div class="grid gap-6 md:grid-cols-2">
    <!-- Uptime Status -->
    <div class="rounded-xl border border-slate-200 bg-white p-4 dark:border-slate-800 dark:bg-slate-900">
        <h3 class="mb-3 font-semibold text-slate-800 dark:text-slate-200">Uptime per Site (Uptime Kuma)</h3>
        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead>
                    <tr class="text-left text-xs uppercase text-slate-500">
                        <th class="px-4 py-3">Site</th>
                        <th class="px-4 py-3">Status</th>
                        <th class="px-4 py-3">Uptime %</th>
                        <th class="px-4 py-3">Last Check</th>
                    </tr>
                </thead>
                <tbody id="uptime-table">
                    <tr>
                        <td colspan="4" class="px-4 py-8 text-center text-slate-500">Memuat...</td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>

    <!-- SSL Status -->
    <div class="rounded-xl border border-slate-200 bg-white p-4 dark:border-slate-800 dark:bg-slate-900">
        <h3 class="mb-3 font-semibold text-slate-800 dark:text-slate-200">Status SSL per Domain</h3>
        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead>
                    <tr class="text-left text-xs uppercase text-slate-500">
                        <th class="px-4 py-3">Domain</th>
                        <th class="px-4 py-3">Status</th>
                        <th class="px-4 py-3">Expired</th>
                        <th class="px-4 py-3">Hari Tersisa</th>
                    </tr>
                </thead>
                <tbody id="ssl-table">
                    <tr>
                        <td colspan="4" class="px-4 py-8 text-center text-slate-500">Memuat...</td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>
</div>

@endsection

@push('scripts')
<script type="module">
// Chart tersedia global dari app.js (bundled via Vite)
Chart.defaults.font.family = 'inherit';
Chart.defaults.font.size = 11;
Chart.defaults.color = '#64748b';
Chart.defaults.plugins.legend.display = false;
Chart.defaults.elements.point.radius = 0;
Chart.defaults.elements.point.hoverRadius = 4;
Chart.defaults.interaction.mode = 'index';
Chart.defaults.interaction.intersect = false;

const metricColors = {
    'cpu': 'rgba(239, 68, 68, 0.8)',
    'ram': 'rgba(59, 130, 246, 0.8)',
    'disk': 'rgba(245, 158, 11, 0.8)',
    'network': 'rgba(16, 185, 129, 0.8)',
};

// State
let trafficChart = null;
let refreshInterval = null;
const REFRESH_INTERVAL_MS = 30000;
let currentAttacksPage = 1;

// Helper: format timestamp
function formatTime(ts) {
    const date = new Date(ts * 1000);
    return date.toLocaleTimeString('id-ID', { hour: '2-digit', minute: '2-digit' });
}

function formatDateTime(ts) {
    const date = new Date(ts * 1000);
    return date.toLocaleString('id-ID', {
        day: '2-digit', month: '2-digit', year: 'numeric',
        hour: '2-digit', minute: '2-digit', second: '2-digit'
    });
}

// Severity badge classes
function severityBadgeClass(severity) {
    const classes = {
        'critical': 'bg-red-100 text-red-700 dark:bg-red-900 dark:text-red-200',
        'high': 'bg-orange-100 text-orange-700 dark:bg-orange-900 dark:text-orange-200',
        'medium': 'bg-amber-100 text-amber-700 dark:bg-amber-900 dark:text-amber-200',
        'low': 'bg-sky-100 text-sky-700 dark:bg-sky-900 dark:text-sky-200',
        'info': 'bg-slate-200 text-slate-600 dark:bg-slate-700 dark:text-slate-300',
    };
    return classes[severity] || 'bg-slate-200 text-slate-600 dark:bg-slate-700 dark:text-slate-300';
}

// Adjust color for different sites
function getSiteColor(siteIndex, metric) {
    const palettes = {
        'cpu': ['rgba(239, 68, 68, 0.8)', 'rgba(59, 130, 246, 0.8)', 'rgba(245, 158, 11, 0.8)', 'rgba(16, 185, 129, 0.8)', 'rgba(139, 92, 246, 0.8)'],
        'ram': ['rgba(239, 68, 68, 0.8)', 'rgba(59, 130, 246, 0.8)', 'rgba(245, 158, 11, 0.8)', 'rgba(16, 185, 129, 0.8)', 'rgba(139, 92, 246, 0.8)'],
        'disk': ['rgba(239, 68, 68, 0.8)', 'rgba(59, 130, 246, 0.8)', 'rgba(245, 158, 11, 0.8)', 'rgba(16, 185, 129, 0.8)', 'rgba(139, 92, 246, 0.8)'],
        'network': ['rgba(239, 68, 68, 0.8)', 'rgba(59, 130, 246, 0.8)', 'rgba(245, 158, 11, 0.8)', 'rgba(16, 185, 129, 0.8)', 'rgba(139, 92, 246, 0.8)'],
        'traffic': ['rgba(239, 68, 68, 0.8)', 'rgba(59, 130, 246, 0.8)', 'rgba(245, 158, 11, 0.8)', 'rgba(16, 185, 129, 0.8)', 'rgba(139, 92, 246, 0.8)'],
    };
    const palette = palettes[metric] || palettes['traffic'];
    return palette[siteIndex % palette.length];
}

function getSiteBgColor(siteIndex, metric) {
    return getSiteColor(siteIndex, metric).replace('0.8', '0.1');
}

// Fetch summary stats
async function fetchSummary() {
    try {
        const response = await fetch('{{ route("security.monitoring.dashboard.summary") }}', {
            headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
        });
        if (!response.ok) throw new Error(`HTTP ${response.status}`);
        const data = await response.json();

        document.getElementById('stat-critical').textContent = data.open_incidents?.critical ?? 0;
        document.getElementById('stat-high').textContent = data.open_incidents?.high ?? 0;
        document.getElementById('stat-medium').textContent = data.open_incidents?.medium ?? 0;
        document.getElementById('stat-waf').textContent = data.waf_blocked_today ?? 0;
        document.getElementById('stat-failed-logins').textContent = data.failed_logins_24h ?? 0;
        document.getElementById('stat-ssl').textContent = data.ssl_expiring_14d ?? 0;

        const totalOpen = Object.values(data.open_incidents || {}).reduce((a, b) => a + b, 0);
        document.getElementById('stat-total-open').textContent = totalOpen;
    } catch (e) {
        console.error('Gagal fetch summary:', e);
    }
}

// Fetch and render traffic chart
async function fetchAndRenderTraffic() {
    const range = document.getElementById('traffic-range').value;

    try {
        const response = await fetch(`{{ route("security.monitoring.dashboard.traffic") }}?range=${range}`, {
            headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
        });
        if (!response.ok) throw new Error(`HTTP ${response.status}`);
        const data = await response.json();

        renderTrafficChart(data.data, data.anomalies);
    } catch (e) {
        console.error('Gagal fetch traffic:', e);
    }
}

function renderTrafficChart(data, anomalies) {
    const ctx = document.getElementById('traffic-chart').getContext('2d');
    const legendEl = document.getElementById('traffic-legend');

    // Prepare datasets
    const sites = Object.keys(data);
    const datasets = sites.map((site, i) => ({
        label: site,
        data: data[site]?.map(d => ({ x: d.time * 1000, y: d.value })) || [],
        borderColor: getSiteColor(i, 'traffic'),
        backgroundColor: getSiteBgColor(i, 'traffic'),
        borderWidth: 2,
        fill: true,
        tension: 0.3,
    }));

    // Mark anomaly points
    datasets.forEach((dataset, si) => {
        if (!data[dataset.label]) return;
        dataset.data.forEach((point, pi) => {
            const anomaly = anomalies.find(a => a.site === dataset.label && a.time === point.x / 1000);
            if (anomaly) {
                // Store anomaly info for tooltip
                point.anomaly = true;
                point.baseline = anomaly.baseline;
            }
        });
    });

    if (trafficChart) {
        trafficChart.destroy();
    }

    trafficChart = new Chart(ctx, {
        type: 'line',
        data: { datasets },
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
                        callback: (value) => value + ' req/s',
                    },
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
                            const value = context.parsed.y.toFixed(1);
                            const anomalyInfo = context.raw.anomaly ? ` (ANOMALI! Baseline: ${context.raw.baseline.toFixed(1)})` : '';
                            return `${context.dataset.label}: ${value} req/s${anomalyInfo}`;
                        },
                    },
                },
            },
        },
    });

    // Render legend
    legendEl.innerHTML = '';
    datasets.forEach((dataset, i) => {
        const item = document.createElement('span');
        item.className = 'flex items-center gap-1.5 text-xs';
        item.innerHTML = `
            <span class="w-3 h-3 rounded" style="background: ${dataset.borderColor}"></span>
            <span class="font-medium">${dataset.label}</span>
        `;
        legendEl.appendChild(item);
    });

    // Add anomaly legend if any
    if (anomalies.length > 0) {
        const anomalyBadge = document.createElement('span');
        anomalyBadge.className = 'ml-4 flex items-center gap-1.5 text-xs text-red-600 dark:text-red-400';
        anomalyBadge.innerHTML = `
            <span class="w-3 h-3 rounded-full bg-red-500"></span>
            <span>${anomalies.length} anomali terdeteksi (>3x baseline)</span>
        `;
        legendEl.appendChild(anomalyBadge);
    }
}

// Fetch attacks
async function fetchAttacks(page = 1) {
    currentAttacksPage = page;

    try {
        const response = await fetch(`{{ route("security.monitoring.dashboard.attacks") }}?page=${page}&per_page=25`, {
            headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
        });
        if (!response.ok) throw new Error(`HTTP ${response.status}`);
        const data = await response.json();

        renderAttacksTable(data);
        renderAttacksPagination(data);
    } catch (e) {
        console.error('Gagal fetch attacks:', e);
    }
}

function renderAttacksTable(data) {
    const tbody = document.getElementById('attacks-table');
    const countEl = document.getElementById('attacks-count');

    countEl.textContent = `${data.total} entri`;

    if (!data.data || data.data.length === 0) {
        tbody.innerHTML = '<tr><td colspan="6" class="px-4 py-8 text-center text-slate-500">Tidak ada data serangan</td></tr>';
        return;
    }

    tbody.innerHTML = data.data.map(attack => `
        <tr class="border-t border-slate-100 dark:border-slate-800">
            <td class="px-4 py-3 font-mono text-xs">${formatDateTime(new Date(attack.time).getTime() / 1000)}</td>
            <td class="px-4 py-3 font-mono text-xs">${attack.ip}</td>
            <td class="px-4 py-3 text-uppercase">${attack.country || '-'}</td>
            <td class="px-4 py-3">
                <span class="rounded-full px-2 py-0.5 text-xs font-medium bg-slate-100 text-slate-700 dark:bg-slate-800 dark:text-slate-300">
                    ${attack.type.replace('_', ' ').toUpperCase()}
                </span>
            </td>
            <td class="px-4 py-3 text-xs truncate max-w-xs" title="${attack.target_url}">${attack.target_url}</td>
            <td class="px-4 py-3">
                <span class="rounded-full px-2 py-0.5 text-xs font-medium ${severityBadgeClass(attack.severity)}">
                    ${attack.severity.toUpperCase()}
                </span>
            </td>
        </tr>
    `).join('');
}

function renderAttacksPagination(data) {
    const infoEl = document.getElementById('attacks-pagination-info');
    const prevBtn = document.getElementById('attacks-prev');
    const nextBtn = document.getElementById('attacks-next');

    infoEl.textContent = `Halaman ${data.current_page} dari ${data.last_page} (${data.total} total)`;
    prevBtn.disabled = data.current_page <= 1;
    nextBtn.disabled = data.current_page >= data.last_page;

    prevBtn.onclick = () => fetchAttacks(data.current_page - 1);
    nextBtn.onclick = () => fetchAttacks(data.current_page + 1);
}

// Fetch services (uptime + SSL)
async function fetchServices() {
    try {
        const response = await fetch('{{ route("security.monitoring.dashboard.services") }}', {
            headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
        });
        if (!response.ok) throw new Error(`HTTP ${response.status}`);
        const data = await response.json();

        renderUptimeTable(data.uptime || []);
        renderSslTable(data.ssl || []);
    } catch (e) {
        console.error('Gagal fetch services:', e);
    }
}

function renderUptimeTable(uptime) {
    const tbody = document.getElementById('uptime-table');

    if (!uptime || uptime.length === 0) {
        tbody.innerHTML = '<tr><td colspan="4" class="px-4 py-8 text-center text-slate-500">Belum ada data uptime</td></tr>';
        return;
    }

    tbody.innerHTML = uptime.map(site => {
        const statusClass = site.status === 'up'
            ? 'bg-green-50 text-green-700 dark:bg-green-950 dark:text-green-400'
            : 'bg-red-50 text-red-700 dark:bg-red-950 dark:text-red-400';
        const statusLabel = site.status === 'up' ? 'Online' : 'Offline';

        return `
            <tr class="border-t border-slate-100 dark:border-slate-800">
                <td class="px-4 py-3 font-medium">${site.site}</td>
                <td class="px-4 py-3">
                    <span class="inline-flex items-center gap-1 rounded-full px-2.5 py-0.5 text-xs font-medium ${statusClass}">
                        <span class="h-1.5 w-1.5 rounded-full ${site.status === 'up' ? 'bg-green-500' : 'bg-red-500'}"></span>
                        ${statusLabel}
                    </span>
                </td>
                <td class="px-4 py-3">${site.uptime_pct?.toFixed(2) ?? '-'}%</td>
                <td class="px-4 py-3 text-slate-500">${site.last_check || '-'}</td>
            </tr>
        `;
    }).join('');
}

function renderSslTable(ssl) {
    const tbody = document.getElementById('ssl-table');

    if (!ssl || ssl.length === 0) {
        tbody.innerHTML = '<tr><td colspan="4" class="px-4 py-8 text-center text-slate-500">Belum ada data SSL</td></tr>';
        return;
    }

    tbody.innerHTML = ssl.map(cert => {
        const daysLeft = cert.days_left;
        let statusClass, statusLabel;

        if (cert.status === 'expired') {
            statusClass = 'bg-red-50 text-red-700 dark:bg-red-950 dark:text-red-400';
            statusLabel = 'Expired';
        } else if (cert.status === 'expiring' || (daysLeft !== null && daysLeft <= 14)) {
            statusClass = 'bg-amber-50 text-amber-700 dark:bg-amber-950 dark:text-amber-400';
            statusLabel = 'Expiring Soon';
        } else {
            statusClass = 'bg-green-50 text-green-700 dark:bg-green-950 dark:text-green-400';
            statusLabel = 'Valid';
        }

        return `
            <tr class="border-t border-slate-100 dark:border-slate-800">
                <td class="px-4 py-3 font-mono text-xs">${cert.domain}</td>
                <td class="px-4 py-3">
                    <span class="inline-flex items-center gap-1 rounded-full px-2.5 py-0.5 text-xs font-medium ${statusClass}">
                        <span class="h-1.5 w-1.5 rounded-full ${cert.status === 'expired' ? 'bg-red-500' : (cert.status === 'expiring' ? 'bg-amber-500' : 'bg-green-500')}"></span>
                        ${statusLabel}
                    </span>
                </td>
                <td class="px-4 py-3 text-xs">${cert.expires_at ? formatDateTime(new Date(cert.expires_at).getTime() / 1000) : '-'}</td>
                <td class="px-4 py-3">${daysLeft !== null ? daysLeft + ' hari' : '-'}</td>
            </tr>
        `;
    }).join('');
}

function updateLastUpdated() {
    const el = document.getElementById('last-updated');
    if (el) {
        el.textContent = `Terakhir update: ${new Date().toLocaleTimeString('id-ID')}`;
    }
}

// Auto-refresh
function startAutoRefresh() {
    stopAutoRefresh();
    refreshInterval = setInterval(() => {
        fetchSummary();
        fetchAndRenderTraffic();
        fetchAttacks(currentAttacksPage);
        fetchServices();
    }, REFRESH_INTERVAL_MS);
}

function stopAutoRefresh() {
    if (refreshInterval) {
        clearInterval(refreshInterval);
        refreshInterval = null;
    }
}

// Event listeners
document.getElementById('traffic-range').addEventListener('change', fetchAndRenderTraffic);
document.getElementById('refresh-attacks').addEventListener('click', () => fetchAttacks(currentAttacksPage));

// Initialize
document.addEventListener('DOMContentLoaded', () => {
    fetchSummary();
    fetchAndRenderTraffic();
    fetchAttacks(1);
    fetchServices();
    startAutoRefresh();
    updateLastUpdated();
});

window.addEventListener('beforeunload', stopAutoRefresh);
</script>
@endpush