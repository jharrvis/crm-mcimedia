@extends('layouts.app')

@section('title', 'Jurnal Tindakan Keamanan')

@section('content')
@php $inputClass = 'rounded-lg border border-slate-300 px-3 py-2 text-sm dark:border-slate-700 dark:bg-slate-800'; @endphp

<div class="mb-4 flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between">
    <form method="GET" action="{{ route('security.actions.index') }}" class="flex items-end gap-2">
        <div>
            <label class="mb-1 block text-xs font-medium text-slate-500">Klien</label>
            <select name="client_id" class="{{ $inputClass }}">
                <option value="">Semua klien</option>
                @foreach ($clients as $c)
                    <option value="{{ $c->id }}" @selected((string) request('client_id') === (string) $c->id)>{{ $c->name }}</option>
                @endforeach
            </select>
        </div>
        <button class="rounded-lg border border-slate-300 px-4 py-2 text-sm font-medium hover:bg-slate-50 dark:border-slate-700 dark:hover:bg-slate-800">Filter</button>
    </form>
    <div class="flex gap-2">
        <a href="{{ route('security.index') }}" class="rounded-lg border border-slate-300 px-4 py-2 text-sm font-medium hover:bg-slate-50 dark:border-slate-700 dark:hover:bg-slate-800">Dashboard</a>
        <a href="{{ route('security.actions.create', request('client_id') ? ['client_id' => request('client_id')] : []) }}"
           class="rounded-lg bg-indigo-600 px-4 py-2 text-sm font-semibold text-white hover:bg-indigo-700">Catat tindakan</a>
    </div>
</div>

<div class="overflow-x-auto rounded-xl border border-slate-200 bg-white dark:border-slate-800 dark:bg-slate-900">
    <table class="w-full text-sm">
        <thead>
            <tr class="text-left text-xs uppercase text-slate-500">
                <th class="px-4 py-3">Tanggal</th>
                <th class="px-4 py-3">Klien</th>
                <th class="px-4 py-3">Tindakan</th>
                <th class="px-4 py-3">Dikerjakan oleh</th>
                <th class="px-4 py-3">Hasil</th>
                <th class="px-4 py-3 text-right">Aksi</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($actions as $action)
                <tr class="border-t border-slate-100 align-top dark:border-slate-800">
                    <td class="px-4 py-3 text-slate-500">{{ tgl_id($action->acted_at) }}</td>
                    <td class="px-4 py-3">{{ $action->client?->name ?? '—' }}</td>
                    <td class="px-4 py-3 max-w-md">{{ \Illuminate\Support\Str::limit($action->action, 160) }}</td>
                    <td class="px-4 py-3">{{ $action->performed_by ?? '—' }}</td>
                    <td class="px-4 py-3 max-w-md text-slate-500">{{ $action->result ? \Illuminate\Support\Str::limit($action->result, 120) : '—' }}</td>
                    <td class="px-4 py-3 text-right whitespace-nowrap">
                        <a href="{{ route('security.actions.edit', $action) }}" class="text-sm text-indigo-600 hover:underline">Ubah</a>
                        <form method="POST" action="{{ route('security.actions.destroy', $action) }}" class="inline"
                              onsubmit="return confirm('Hapus catatan tindakan ini?')">
                            @csrf
                            @method('DELETE')
                            <button class="ml-2 text-sm text-red-600 hover:underline">Hapus</button>
                        </form>
                    </td>
                </tr>
            @empty
                <tr><td colspan="6" class="px-4 py-8 text-center text-slate-500">Belum ada catatan tindakan.</td></tr>
            @endforelse
        </tbody>
    </table>
</div>

<div class="mt-4">{{ $actions->links() }}</div>
@endsection
