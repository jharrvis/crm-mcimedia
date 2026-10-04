@extends('layouts.app')

@section('title', 'Insiden Keamanan')

@section('content')
@php
    $inputClass = 'rounded-lg border border-slate-300 px-3 py-2 text-sm dark:border-slate-700 dark:bg-slate-800';
@endphp

<div class="mb-4 flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between">
    <form method="GET" action="{{ route('security.incidents.index') }}" class="flex flex-wrap items-end gap-2" id="incidents-filter-form">
        <div>
            <label class="mb-1 block text-xs font-medium text-slate-500">Klien</label>
            <select name="client_id" class="{{ $inputClass }}">
                <option value="">Semua klien</option>
                @foreach ($clients as $c)
                    <option value="{{ $c->id }}" @selected((string) request('client_id') === (string) $c->id)>{{ $c->name }}</option>
                @endforeach
            </select>
        </div>
        <div>
            <label class="mb-1 block text-xs font-medium text-slate-500">Keparahan</label>
            <select name="severity" class="{{ $inputClass }}">
                <option value="all">Semua</option>
                @foreach ($severities as $s)
                    <option value="{{ $s->value }}" @selected(request('severity') === $s->value)>{{ $s->label() }}</option>
                @endforeach
            </select>
        </div>
        <div>
            <label class="mb-1 block text-xs font-medium text-slate-500">Status</label>
            <select name="status" class="{{ $inputClass }}">
                <option value="all">Semua</option>
                @foreach ($statuses as $s)
                    <option value="{{ $s->value }}" @selected(request('status') === $s->value)>{{ $s->label() }}</option>
                @endforeach
            </select>
        </div>
        <div>
            <label class="mb-1 block text-xs font-medium text-slate-500">Cari judul</label>
            <input name="q" value="{{ request('q') }}" class="{{ $inputClass }}">
        </div>
        <button type="submit" class="rounded-lg border border-slate-300 px-4 py-2 text-sm font-medium hover:bg-slate-50 dark:border-slate-700 dark:hover:bg-slate-800">Filter</button>
        <button type="button" id="incidents-refresh-btn" class="rounded-lg border border-slate-300 px-4 py-2 text-sm font-medium hover:bg-slate-50 dark:border-slate-700 dark:hover:bg-slate-800" title="Refresh manual">Refresh</button>
    </form>
    <div class="flex gap-2">
        <a href="{{ route('security.index') }}" class="rounded-lg border border-slate-300 px-4 py-2 text-sm font-medium hover:bg-slate-50 dark:border-slate-700 dark:hover:bg-slate-800">Dashboard</a>
        <a href="{{ route('security.incidents.create', request('client_id') ? ['client_id' => request('client_id')] : []) }}"
           class="rounded-lg bg-indigo-600 px-4 py-2 text-sm font-semibold text-white hover:bg-indigo-700">Catat insiden</a>
    </div>
</div>

<div class="overflow-x-auto rounded-xl border border-slate-200 bg-white dark:border-slate-800 dark:bg-slate-900 relative">
    <table class="w-full text-sm" id="incidents-table">
        <thead>
            <tr class="text-left text-xs uppercase text-slate-500">
                <th class="px-4 py-3">Waktu</th>
                <th class="px-4 py-3">Klien</th>
                <th class="px-4 py-3">Keparahan</th>
                <th class="px-4 py-3">Sumber</th>
                <th class="px-4 py-3">Judul</th>
                <th class="px-4 py-3">Status</th>
                <th class="px-4 py-3 text-right">Aksi</th>
            </tr>
        </thead>
        <tbody id="incidents-tbody"
            data-api-url="{{ route('security.incidents.api') }}"
            data-client-id="{{ request('client_id', '') }}"
            data-severity="{{ request('severity', 'all') }}"
            data-status="{{ request('status', 'all') }}"
            data-q="{{ request('q', '') }}">
            @include('security.incidents._table_rows')
        </tbody>
    </table>
    
    <!-- Subtle new data indicator -->
    <div id="incidents-new-indicator" class="hidden absolute top-2 right-2 z-10 bg-emerald-100 dark:bg-emerald-900 text-emerald-800 dark:text-emerald-200 px-3 py-1 rounded-full text-xs font-medium shadow-lg transition-opacity duration-300">
        <span class="flex items-center gap-1">
            <span class="animate-pulse w-1.5 h-1.5 rounded-full bg-emerald-500"></span>
            Data baru tersedia
        </span>
    </div>
</div>

<div class="mt-4" id="incidents-pagination">{{ $incidents->links() }}</div>

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

    // Build query string from current filter form values
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

    // Fetch and update table
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

            // Update table rows
            tbody.innerHTML = renderRows(data.data);

            // Update pagination
            if (pagination && data.current_page && data.last_page) {
                pagination.innerHTML = renderPagination(data);
            }

            // Show subtle indicator if new data arrived
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

    function renderRows(incidents) {
        if (!incidents || incidents.length === 0) {
            return '<tr><td colspan="7" class="px-4 py-8 text-center text-slate-500">Belum ada insiden.</td></tr>';
        }

        return incidents.map(incident => `
            <tr class="border-t border-slate-100 dark:border-slate-800">
                <td class="px-4 py-3 text-slate-500">${incident.occurred_at ? new Date(incident.occurred_at).toLocaleString('id-ID', {day: '2-digit', month: '2-digit', year: 'numeric', hour: '2-digit', minute: '2-digit'}) : '—'}</td>
                <td class="px-4 py-3">${incident.client?.name ?? '—'}</td>
                <td class="px-4 py-3"><span class="rounded-full px-2 py-0.5 text-xs font-medium ${incident.severity?.badgeClass ?? ''}">${incident.severity?.label ?? incident.severity}</span></td>
                <td class="px-4 py-3">${incident.source?.label ?? incident.source}</td>
                <td class="px-4 py-3">
                    <span class="font-medium">${incident.title}</span>
                    ${incident.description ? `<p class="mt-1 max-w-md text-xs text-slate-500">${incident.description.substring(0, 120)}${incident.description.length > 120 ? '...' : ''}</p>` : ''}
                </td>
                <td class="px-4 py-3"><span class="rounded-full px-2 py-0.5 text-xs font-medium ${incident.status?.badgeClass ?? ''}">${incident.status?.label ?? incident.status}</span></td>
                <td class="px-4 py-3 text-right whitespace-nowrap">
                    <a href="${incident.edit_url ?? '/security/incidents/' + incident.id + '/edit'}" class="text-sm text-indigo-600 hover:underline">Ubah</a>
                    <form method="POST" action="${incident.destroy_url ?? '/security/incidents/' + incident.id}" class="inline" onsubmit="return confirm('Hapus insiden ini?')">
                        <input type="hidden" name="_token" value="${document.querySelector('meta[name=csrf-token]')?.content ?? ''}">
                        <input type="hidden" name="_method" value="DELETE">
                        <button class="ml-2 text-sm text-red-600 hover:underline">Hapus</button>
                    </form>
                </td>
            </tr>
        `).join('');
    }

    function renderPagination(data) {
        // Simple pagination render - in practice, you might want to reuse Laravel's pagination view
        if (data.last_page <= 1) return '';
        let html = '<nav aria-label="Pagination" class="flex justify-center"><ul class="flex items-center gap-1">';
        for (let i = 1; i <= data.last_page; i++) {
            if (i === data.current_page) {
                html += `<li><span class="px-3 py-1 text-sm font-medium bg-indigo-600 text-white rounded">${i}</span></li>`;
            } else {
                html += `<li><a href="?page=${i}${buildFilterParams() ? '&' + buildFilterParams() : ''}" class="px-3 py-1 text-sm text-slate-600 hover:bg-slate-100 dark:hover:bg-slate-800 rounded">${i}</a></li>`;
            }
        }
        html += '</ul></nav>';
        return html;
    }

    function showNewIndicator() {
        newIndicator.classList.remove('hidden');
        newIndicator.style.opacity = '1';
        setTimeout(() => {
            newIndicator.style.opacity = '0';
            setTimeout(() => newIndicator.classList.add('hidden'), 300);
        }, 3000);
    }

    // Manual refresh button
    refreshBtn.addEventListener('click', () => fetchIncidents(true));

    // Auto-poll
    function startPolling() {
        if (pollInterval) clearInterval(pollInterval);
        pollInterval = setInterval(() => fetchIncidents(false), POLL_INTERVAL_MS);
    }

    function stopPolling() {
        if (pollInterval) clearInterval(pollInterval);
        pollInterval = null;
    }

    // Pause polling when tab is hidden, resume when visible
    document.addEventListener('visibilitychange', () => {
        if (document.hidden) {
            stopPolling();
        } else {
            startPolling();
        }
    });

    // Start polling
    startPolling();

    // Also re-fetch when filter form is submitted (after browser navigates back)
    window.addEventListener('pageshow', (event) => {
        if (event.persisted) {
            fetchIncidents(true);
        }
    });
})();
</script>
@endpush
