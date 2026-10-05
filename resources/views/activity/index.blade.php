@extends('layouts.app')

@section('title', 'Aktivitas')

@section('content')
<x-page-header title="Aktivitas" subtitle="Jejak audit internal: siapa membuat, mengubah, atau menghapus data." icon="history" />

<x-card>
    <x-table>
        <thead><tr>
            <th>Waktu</th>
            <th>Pengguna</th>
            <th>Aksi</th>
            <th>IP</th>
        </tr></thead>
        <tbody>
            @forelse ($logs as $log)
                <tr>
                    <td class="whitespace-nowrap">{{ $log->created_at->format('d/m/Y H:i') }}</td>
                    <td>{{ $log->user?->name ?? 'Sistem' }}</td>
                    <td>{{ $log->description }}</td>
                    <td class="text-slate-400">{{ $log->ip_address ?? '—' }}</td>
                </tr>
            @empty
                <tr><td colspan="4">
                    <x-empty-state title="Belum ada aktivitas tercatat" icon="history" />
                </td></tr>
            @endforelse
        </tbody>
    </x-table>

    @if ($logs->hasPages())
        <div class="mt-4">{{ $logs->links() }}</div>
    @endif
</x-card>
@endsection
