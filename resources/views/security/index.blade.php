@extends('layouts.app')

@section('title', 'Keamanan')

@section('content')
@php
    $severityClass = fn (\App\Domains\Security\Enums\IncidentSeverity $s) => $s->badgeClass();
    $totalOpen = fn (array $counts) => array_sum($counts);
@endphp

<div class="mb-4 flex flex-wrap items-center gap-2">
    <a href="{{ route('security.incidents.index') }}" class="rounded-lg border border-slate-300 px-4 py-2 text-sm font-medium hover:bg-slate-50 dark:border-slate-700 dark:hover:bg-slate-800">Insiden</a>
    <a href="{{ route('security.actions.index') }}" class="rounded-lg border border-slate-300 px-4 py-2 text-sm font-medium hover:bg-slate-50 dark:border-slate-700 dark:hover:bg-slate-800">Jurnal tindakan</a>
    <a href="{{ route('security.reports.index') }}" class="rounded-lg border border-slate-300 px-4 py-2 text-sm font-medium hover:bg-slate-50 dark:border-slate-700 dark:hover:bg-slate-800">Laporan</a>
    <a href="{{ route('security.monitoring.dashboard') }}" class="rounded-lg border border-slate-300 px-4 py-2 text-sm font-medium hover:bg-slate-50 dark:border-slate-700 dark:hover:bg-slate-800">Monitoring Dashboard</a>
    <a href="{{ route('security.incidents.create') }}" class="ml-auto rounded-lg bg-indigo-600 px-4 py-2 text-sm font-semibold text-white hover:bg-indigo-700">Catat insiden</a>
</div>

<p class="mb-4 text-sm text-slate-500">
    Ringkasan per klien: insiden terbuka per tingkat keparahan, tindakan bulan berjalan, jumlah laporan,
    dan status tautan laporan publik. API ingest script monitoring: <code>POST /api/security/events</code>.
</p>

<div class="overflow-x-auto rounded-xl border border-slate-200 bg-white dark:border-slate-800 dark:bg-slate-900">
    <table class="w-full text-sm">
        <thead>
            <tr class="text-left text-xs uppercase text-slate-500">
                <th class="px-4 py-3">Klien</th>
                <th class="px-4 py-3">Insiden terbuka</th>
                <th class="px-4 py-3">Tindakan bulan ini</th>
                <th class="px-4 py-3">Laporan</th>
                <th class="px-4 py-3">Tautan publik klien</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($clients as $client)
                @php $counts = $openByClient[$client->id] ?? []; @endphp
                <tr class="border-t border-slate-100 align-top dark:border-slate-800">
                    <td class="px-4 py-3">
                        <a href="{{ route('security.incidents.index', ['client_id' => $client->id]) }}" class="font-medium text-indigo-600 hover:underline">{{ $client->name }}</a>
                    </td>
                    <td class="px-4 py-3">
                        @if ($totalOpen($counts) === 0)
                            <span class="text-slate-500">Tidak ada</span>
                        @else
                            <div class="flex flex-wrap gap-1">
                                @foreach ($severities as $severity)
                                    @if (($counts[$severity->value] ?? 0) > 0)
                                        <span class="rounded-full px-2 py-0.5 text-xs font-medium {{ $severityClass($severity) }}">
                                            {{ $severity->label() }}: {{ $counts[$severity->value] }}
                                        </span>
                                    @endif
                                @endforeach
                            </div>
                        @endif
                    </td>
                    <td class="px-4 py-3">{{ $actionsThisMonth[$client->id] ?? 0 }}</td>
                    <td class="px-4 py-3">
                        <a href="{{ route('security.reports.index', ['client_id' => $client->id]) }}" class="text-indigo-600 hover:underline">
                            {{ $reportCounts[$client->id] ?? 0 }} laporan
                        </a>
                    </td>
                    <td class="px-4 py-3">
                        @if ($client->hasSecurityPortal())
                            <div class="flex flex-col gap-1">
                                <input readonly value="{{ route('security.portal.show', ['token' => $client->security_portal_token]) }}"
                                       onclick="this.select()"
                                       class="w-72 rounded-lg border border-slate-300 px-2 py-1 text-xs dark:border-slate-700 dark:bg-slate-800">
                                <form method="POST" action="{{ route('clients.security-portal.revoke', $client) }}"
                                      onsubmit="return confirm('Cabut tautan laporan untuk {{ $client->name }}?')">
                                    @csrf
                                    @method('DELETE')
                                    <button class="text-xs text-red-600 hover:underline">Cabut tautan</button>
                                </form>
                            </div>
                        @else
                            <form method="POST" action="{{ route('clients.security-portal.generate', $client) }}">
                                @csrf
                                <button class="rounded-lg border border-slate-300 px-3 py-1.5 text-xs font-medium hover:bg-slate-50 dark:border-slate-700 dark:hover:bg-slate-800">Buat tautan</button>
                            </form>
                        @endif
                    </td>
                </tr>
            @empty
                <tr><td colspan="5" class="px-4 py-8 text-center text-slate-500">Belum ada klien.</td></tr>
            @endforelse
        </tbody>
    </table>
</div>
@endsection
