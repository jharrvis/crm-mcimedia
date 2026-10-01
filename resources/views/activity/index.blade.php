@extends('layouts.app')

@section('title', 'Aktivitas')

@section('content')
<div class="rounded-xl border border-slate-200 bg-white p-5 dark:border-slate-800 dark:bg-slate-900">
    <p class="mb-4 text-sm text-slate-500">Jejak audit internal: siapa membuat, mengubah, atau menghapus data.</p>
    <div class="overflow-x-auto">
        <table class="w-full text-sm">
            <thead><tr class="text-left text-xs uppercase text-slate-500">
                <th class="py-2 pr-4">Waktu</th><th class="py-2 pr-4">Pengguna</th><th class="py-2 pr-4">Aksi</th><th class="py-2">IP</th>
            </tr></thead>
            <tbody>
                @forelse ($logs as $log)
                    <tr class="border-t border-slate-100 dark:border-slate-800">
                        <td class="whitespace-nowrap py-2 pr-4">{{ $log->created_at->format('d/m/Y H:i') }}</td>
                        <td class="py-2 pr-4">{{ $log->user?->name ?? 'Sistem' }}</td>
                        <td class="py-2 pr-4">{{ $log->description }}</td>
                        <td class="py-2 text-slate-500">{{ $log->ip_address ?? '—' }}</td>
                    </tr>
                @empty
                    <tr><td colspan="4" class="py-6 text-center text-slate-500">Belum ada aktivitas tercatat.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
    <div class="mt-4">{{ $logs->links() }}</div>
</div>
@endsection
