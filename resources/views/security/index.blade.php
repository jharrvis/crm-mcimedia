@extends('layouts.app')

@section('title', 'Keamanan')

@section('content')
@php
    $severityClass = fn (\App\Domains\Security\Enums\IncidentSeverity $s) => $s->badgeClass();
    $totalOpen = fn (array $counts) => array_sum($counts);
@endphp

<x-page-header title="Keamanan" icon="shield-check"
               subtitle="Ringkasan per klien: insiden terbuka per tingkat keparahan, tindakan bulan berjalan, jumlah laporan, dan status tautan laporan publik. API ingest script monitoring: POST /api/security/events.">
    <x-btn :href="route('security.incidents.index')" variant="outline" icon="shield-alert">Insiden</x-btn>
    <x-btn :href="route('security.actions.index')" variant="outline" icon="history">Jurnal tindakan</x-btn>
    <x-btn :href="route('security.reports.index')" variant="outline" icon="file-text">Laporan</x-btn>
    <x-btn :href="route('security.monitoring.dashboard')" variant="outline" icon="activity">Monitoring Dashboard</x-btn>
    <x-btn :href="route('security.incidents.create')" icon="plus">Catat insiden</x-btn>
</x-page-header>

<x-card>
    <x-table>
        <thead><tr>
            <th>Klien</th>
            <th>Insiden terbuka</th>
            <th>Tindakan bulan ini</th>
            <th>Laporan</th>
            <th>Tautan publik klien</th>
        </tr></thead>
        <tbody>
            @forelse ($clients as $client)
                @php $counts = $openByClient[$client->id] ?? []; @endphp
                <tr>
                    <td>
                        <a href="{{ route('security.incidents.index', ['client_id' => $client->id]) }}" class="font-semibold text-brand-600 hover:underline">{{ $client->name }}</a>
                    </td>
                    <td>
                        @if ($totalOpen($counts) === 0)
                            <span class="text-slate-400">Tidak ada</span>
                        @else
                            <div class="flex flex-wrap gap-1">
                                @foreach ($severities as $severity)
                                    @if (($counts[$severity->value] ?? 0) > 0)
                                        <span class="rounded-full px-2 py-1 text-xs font-semibold {{ $severityClass($severity) }}">
                                            {{ $severity->label() }}: {{ $counts[$severity->value] }}
                                        </span>
                                    @endif
                                @endforeach
                            </div>
                        @endif
                    </td>
                    <td>{{ $actionsThisMonth[$client->id] ?? 0 }}</td>
                    <td>
                        <a href="{{ route('security.reports.index', ['client_id' => $client->id]) }}" class="text-brand-600 hover:underline">
                            {{ $reportCounts[$client->id] ?? 0 }} laporan
                        </a>
                    </td>
                    <td>
                        @if ($client->hasSecurityPortal())
                            <div class="flex flex-col gap-1">
                                <input readonly value="{{ route('security.portal.show', ['token' => $client->security_portal_token]) }}"
                                       onclick="this.select()"
                                       class="w-72 rounded-lg border border-slate-300 bg-white px-2 py-1 text-xs dark:border-slate-700 dark:bg-slate-800">
                                <form method="POST" action="{{ route('clients.security-portal.revoke', $client) }}"
                                      onsubmit="return confirm('Cabut tautan laporan untuk {{ $client->name }}?')">
                                    @csrf
                                    @method('DELETE')
                                    <button class="text-xs font-semibold text-rose-600 hover:underline">Cabut tautan</button>
                                </form>
                            </div>
                        @else
                            <form method="POST" action="{{ route('clients.security-portal.generate', $client) }}">
                                @csrf
                                <x-btn type="submit" variant="outline">Buat tautan</x-btn>
                            </form>
                        @endif
                    </td>
                </tr>
            @empty
                <tr><td colspan="5">
                    <x-empty-state title="Belum ada klien" icon="shield-check"
                                   description="Tambahkan klien dulu di menu Klien." />
                </td></tr>
            @endforelse
        </tbody>
    </x-table>
</x-card>
@endsection