@extends('layouts.app')

@section('title', 'Insiden Keamanan')

@section('content')
@php
    $inputClass = 'rounded-lg border border-slate-300 px-3 py-2 text-sm dark:border-slate-700 dark:bg-slate-800';
@endphp

<div class="mb-4 flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between">
    <form method="GET" action="{{ route('security.incidents.index') }}" class="flex flex-wrap items-end gap-2">
        <div>
            <label class="mb-1 block text-xs font-medium text-slate-500">Klien</label>
            <x-client-select
                :clients="$clients"
                :selected="request('client_id')"
                empty-label="Semua klien"
                :class="$inputClass" />
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
        <button class="rounded-lg border border-slate-300 px-4 py-2 text-sm font-medium hover:bg-slate-50 dark:border-slate-700 dark:hover:bg-slate-800">Filter</button>
    </form>
    <div class="flex gap-2">
        <a href="{{ route('security.index') }}" class="rounded-lg border border-slate-300 px-4 py-2 text-sm font-medium hover:bg-slate-50 dark:border-slate-700 dark:hover:bg-slate-800">Dashboard</a>
        <a href="{{ route('security.incidents.create', request('client_id') ? ['client_id' => request('client_id')] : []) }}"
           class="rounded-lg bg-indigo-600 px-4 py-2 text-sm font-semibold text-white hover:bg-indigo-700">Catat insiden</a>
    </div>
</div>

<div class="overflow-x-auto rounded-xl border border-slate-200 bg-white dark:border-slate-800 dark:bg-slate-900">
    <table class="w-full text-sm">
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
        <tbody>
            @forelse ($incidents as $incident)
                <tr class="border-t border-slate-100 dark:border-slate-800">
                    <td class="px-4 py-3 text-slate-500">{{ $incident->occurred_at?->format('d/m/Y H:i') ?? '—' }}</td>
                    <td class="px-4 py-3">{{ $incident->client?->name ?? '—' }}</td>
                    <td class="px-4 py-3"><span class="rounded-full px-2 py-0.5 text-xs font-medium {{ $incident->severity->badgeClass() }}">{{ $incident->severity->label() }}</span></td>
                    <td class="px-4 py-3">{{ $incident->source->label() }}</td>
                    <td class="px-4 py-3">
                        <span class="font-medium">{{ $incident->title }}</span>
                        @if ($incident->description)
                            <p class="mt-1 max-w-md text-xs text-slate-500">{{ \Illuminate\Support\Str::limit($incident->description, 120) }}</p>
                        @endif
                    </td>
                    <td class="px-4 py-3"><span class="rounded-full px-2 py-0.5 text-xs font-medium {{ $incident->status->badgeClass() }}">{{ $incident->status->label() }}</span></td>
                    <td class="px-4 py-3 text-right whitespace-nowrap">
                        <a href="{{ route('security.incidents.edit', $incident) }}" class="text-sm text-indigo-600 hover:underline">Ubah</a>
                        <form method="POST" action="{{ route('security.incidents.destroy', $incident) }}" class="inline"
                              onsubmit="return confirm('Hapus insiden ini?')">
                            @csrf
                            @method('DELETE')
                            <button class="ml-2 text-sm text-red-600 hover:underline">Hapus</button>
                        </form>
                    </td>
                </tr>
            @empty
                <tr><td colspan="7" class="px-4 py-8 text-center text-slate-500">Belum ada insiden.</td></tr>
            @endforelse
        </tbody>
    </table>
</div>

<div class="mt-4">{{ $incidents->links() }}</div>
@endsection
