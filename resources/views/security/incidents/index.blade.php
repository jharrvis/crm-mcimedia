@extends('layouts.app')

@section('title', 'Insiden Keamanan')

@section('content')
@php
    // collect() karena controller mengirim ::cases() (array), bukan Collection.
    $clientOptions = ['' => 'Semua klien'] + collect($clients)->mapWithKeys(fn ($c) => [$c->id => $c->name])->all();
    $severityOptions = ['all' => 'Semua'] + collect($severities)->mapWithKeys(fn ($s) => [$s->value => $s->label()])->all();
    $statusOptions = ['all' => 'Semua'] + collect($statuses)->mapWithKeys(fn ($s) => [$s->value => $s->label()])->all();
@endphp

<x-page-header title="Insiden Keamanan" icon="shield-alert"
               subtitle="Temuan otomatis & manual per klien. Daftar diperbarui otomatis tiap 20 detik.">
    <x-btn :href="route('security.index')" variant="outline" icon="layout-dashboard">Dashboard</x-btn>
    <x-btn :href="route('security.incidents.create', request('client_id') ? ['client_id' => request('client_id')] : [])" icon="plus">Catat insiden</x-btn>
</x-page-header>

<x-card class="mb-4">
    <form method="GET" action="{{ route('security.incidents.index') }}" class="grid gap-2 sm:grid-cols-2 lg:grid-cols-5" id="incidents-filter-form">
        <x-input name="client_id" type="select" :options="$clientOptions" :value="request('client_id')" placeholder="" />
        <x-input name="severity" type="select" :options="$severityOptions" :value="request('severity', 'all')" />
        <x-input name="status" type="select" :options="$statusOptions" :value="request('status', 'all')" />
        <x-input name="q" :value="request('q')" placeholder="Cari judul…" />
        <div class="flex items-center gap-2">
            <x-btn type="submit" variant="dark" icon="search">Filter</x-btn>
            <x-btn type="button" id="incidents-refresh-btn" variant="outline" icon="refresh-cw" title="Refresh manual">Refresh</x-btn>
        </div>
    </form>
</x-card>

<x-card>
    <x-table id="incidents-table">
        <thead><tr>
            <th>Waktu</th>
            <th>Klien</th>
            <th>Keparahan</th>
            <th>Sumber</th>
            <th>Judul</th>
            <th>Status</th>
            <th class="text-right">Aksi</th>
        </tr></thead>
        <tbody id="incidents-tbody"
              data-api-url="{{ route('security.incidents.api') }}"
              data-client-id="{{ request('client_id', '') }}"
              data-severity="{{ request('severity', 'all') }}"
              data-status="{{ request('status', 'all') }}"
              data-q="{{ request('q', '') }}">
            @include('security.incidents._table_rows')
        </tbody>
    </x-table>

    {{-- Indikator data baru (JS di bawah) --}}
    <div id="incidents-new-indicator" class="pointer-events-none sticky bottom-4 ml-auto mr-4 hidden w-fit bg-emerald-100 px-3 py-1 text-xs font-medium text-emerald-800 shadow-lg dark:bg-emerald-900 dark:text-emerald-200">
        <span class="flex items-center gap-1">
            <span class="h-1.5 w-1.5 animate-pulse rounded-full bg-emerald-500"></span>
            Data baru tersedia
        </span>
    </div>
</x-card>

<div class="mt-4" id="incidents-pagination">{{ $incidents->links() }}</div>
@endsection

@push('scripts')
<script>
(function() {
    'use strict';

    const tbody = document.getElementById('incidents-tbody');
    const pagination = document.getElementById('incidents-pagination');
    const refreshBtn = document.getElementById('incidents-refresh-btn');
    const newIndicator = document.getElementById('incidents-new-indicator');
    const filterForm = document.getElementById('incidents-filter-form');

    if (!tbody) return;

    const apiUrl = tbody.dataset.apiUrl;
    let lastTotal = parseInt('{{ $incidents->total() }}', 10);
    let isPolling = false;
    let pollInterval = null;
    const POLL_INTERVAL_MS = 20000; // 20 detik

    // Query string dari nilai filter saat ini.
    function buildFilterParams() {
        const formData = new FormData(filterForm);
        const params = new URLSearchParams();
        for (const [key, value] of formData.entries()) {
            if (value !== '' && value !== 'all') {
                params.append(key, value);
            }
        }
        return params.toString();
    }

    async function fetchIncidents(showIndicator = false) {
        if (isPolling) return;
        isPolling = true;

        try {
            const queryString = buildFilterParams();
            const url = queryString ? `${apiUrl}?${queryString}` : apiUrl;
            const response = await fetch(url, {
                headers: { 'Accept': 'application/json' },
                credentials: 'same-origin'
            });

            if (!response.ok) {
                throw new Error(`HTTP ${response.status}`);
            }

            const data = await response.json();

            tbody.innerHTML = renderRows(data.data);

            if (pagination && data.current_page && data.last_page) {
                pagination.innerHTML = renderPagination(data);
            }

            if (showIndicator && data.total > lastTotal) {
                showNewIndicator();
            }
            lastTotal = data.total;

        } catch (err) {
            console.error('Failed to fetch incidents:', err);
        } finally {
            isPolling = false;
        }
    }

    // Sel di-style oleh .ds-table (lihat app.css), jadi tidak perlu kelas padding.
    function renderRows(incidents) {
        if (!incidents || incidents.length === 0) {
            return '<tr><td colspan="7" class="py-10 text-center text-slate-400">Belum ada insiden.</td></tr>';
        }

        return incidents.map(incident => `
            <tr>
                <td class="text-slate-400">${incident.occurred_at ? new Date(incident.occurred_at).toLocaleString('id-ID', {day: '2-digit', month: '2-digit', year: 'numeric', hour: '2-digit', minute: '2-digit'}) : '—'}</td>
                <td>${incident.client?.name ?? '—'}</td>
                <td><span class="inline-flex items-center rounded-full px-2 py-1 text-xs font-semibold ${incident.severity?.badgeClass ?? ''}">${incident.severity?.label ?? incident.severity}</span></td>
                <td>${incident.source?.label ?? incident.source}</td>
                <td>
                    <span class="font-semibold">${incident.title}</span>
                    ${incident.description ? `<p class="mt-1 max-w-md text-xs text-slate-400">${incident.description.substring(0, 120)}${incident.description.length > 120 ? '...' : ''}</p>` : ''}
                </td>
                <td><span class="inline-flex items-center rounded-full px-2 py-1 text-xs font-semibold ${incident.status?.badgeClass ?? ''}">${incident.status?.label ?? incident.status}</span></td>
                <td class="whitespace-nowrap text-right">
                    <a href="${incident.edit_url ?? '/security/incidents/' + incident.id + '/edit'}" class="text-sm font-semibold text-brand-600 hover:underline">Ubah</a>
                    <span class="mx-1 text-slate-300">|</span>
                    <form method="POST" action="${incident.destroy_url ?? '/security/incidents/' + incident.id}" class="inline" onsubmit="return confirm('Hapus insiden ini?')">
                        <input type="hidden" name="_token" value="${document.querySelector('meta[name=csrf-token]')?.content ?? ''}">
                        <input type="hidden" name="_method" value="DELETE">
                        <button class="text-sm font-semibold text-rose-600 hover:underline">Hapus</button>
                    </form>
                </td>
            </tr>
        `).join('');
    }

    function renderPagination(data) {
        if (data.last_page <= 1) return '';
        let html = '<nav aria-label="Pagination" class="flex justify-center"><ul class="flex items-center gap-1">';
        for (let i = 1; i <= data.last_page; i++) {
            if (i === data.current_page) {
                html += `<li><span class="rounded bg-brand-600 px-3 py-1 text-sm font-medium text-white">${i}</span></li>`;
            } else {
                html += `<li><a href="?page=${i}${buildFilterParams() ? '&' + buildFilterParams() : ''}" class="rounded px-3 py-1 text-sm text-slate-600 hover:bg-slate-100 dark:text-slate-300 dark:hover:bg-slate-800">${i}</a></li>`;
            }
        }
        html += '</ul></nav>';
        return html;
    }

    function showNewIndicator() {
        newIndicator.classList.remove('hidden');
        setTimeout(() => newIndicator.classList.add('hidden'), 3000);
    }

    refreshBtn.addEventListener('click', () => fetchIncidents(true));

    function startPolling() {
        if (pollInterval) clearInterval(pollInterval);
        pollInterval = setInterval(() => fetchIncidents(false), POLL_INTERVAL_MS);
    }

    function stopPolling() {
        if (pollInterval) clearInterval(pollInterval);
        pollInterval = null;
    }

    // Jeda polling saat tab disembunyikan, lanjut saat tampil lagi.
    document.addEventListener('visibilitychange', () => {
        if (document.hidden) {
            stopPolling();
        } else {
            startPolling();
        }
    });

    startPolling();

    window.addEventListener('pageshow', (event) => {
        if (event.persisted) {
            fetchIncidents(true);
        }
    });
})();
</script>
@endpush