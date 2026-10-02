@extends('layouts.public')

@section('title', 'Laporan Keamanan')

@section('content')
<div class="rounded-xl border border-slate-200 bg-white p-6 dark:border-slate-800 dark:bg-slate-900">
    <h1 class="text-lg font-bold">Laporan Keamanan</h1>
    <p class="mt-1 text-sm text-slate-500">
        Klien: <span class="font-medium text-slate-700 dark:text-slate-300">{{ $client->name }}</span>
    </p>

    <div class="mt-4 overflow-x-auto">
        <table class="w-full text-sm">
            <thead>
                <tr class="text-left text-xs uppercase text-slate-500">
                    <th class="px-3 py-2">Periode</th>
                    <th class="px-3 py-2">Dikirim</th>
                    <th class="px-3 py-2 text-right">Berkas</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($reports as $report)
                    <tr class="border-t border-slate-100 dark:border-slate-800">
                        <td class="px-3 py-3 font-medium">{{ $report->period }}</td>
                        <td class="px-3 py-3 text-slate-500">{{ $report->sent_at?->format('d/m/Y') ?? '—' }}</td>
                        <td class="px-3 py-3 text-right">
                            @if ($report->hasFile())
                                <a href="{{ route('security.portal.download', ['token' => $client->security_portal_token, 'report' => $report]) }}"
                                   class="text-indigo-600 hover:underline">Unduh PDF</a>
                            @else
                                <span class="text-slate-400">—</span>
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="3" class="px-3 py-8 text-center text-slate-500">Belum ada laporan yang dipublikasikan.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>
@endsection
