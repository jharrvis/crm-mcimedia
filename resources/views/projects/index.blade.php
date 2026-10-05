@extends('layouts.app')

@section('title', 'Project')

@section('content')
<x-page-header title="Project" subtitle="Semua project klien dan progres tugasnya." icon="folder-kanban">
    <x-btn :href="route('projects.create')" icon="plus">Tambah project</x-btn>
</x-page-header>

<x-card>
    <form method="GET" action="{{ route('projects.index') }}" class="mb-4 grid gap-2 sm:grid-cols-2 lg:grid-cols-4">
        <input name="q" value="{{ request('q') }}" placeholder="Cari judul…"
               class="w-full rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm placeholder:text-slate-400 focus:border-brand-500 focus:outline-none focus:ring-2 focus:ring-brand-100 dark:border-slate-700 dark:bg-slate-800 dark:text-slate-100 dark:focus:border-brand-400 dark:focus:ring-brand-900">
        <select name="client_id"
                class="w-full rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm focus:border-brand-500 focus:outline-none focus:ring-2 focus:ring-brand-100 dark:border-slate-700 dark:bg-slate-800 dark:text-slate-100">
            <option value="">Semua klien</option>
            @foreach ($clients as $client)
                <option value="{{ $client->id }}" @selected(request('client_id') == $client->id)>{{ $client->name }}</option>
            @endforeach
        </select>
        <select name="status"
                class="w-full rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm focus:border-brand-500 focus:outline-none focus:ring-2 focus:ring-brand-100 dark:border-slate-700 dark:bg-slate-800 dark:text-slate-100">
            <option value="">Semua status</option>
            @foreach ($statuses as $status)
                <option value="{{ $status->value }}" @selected(request('status') === $status->value)>{{ $status->label() }}</option>
            @endforeach
        </select>
        <div class="flex items-center gap-2">
            <x-btn type="submit" variant="dark" icon="search">Filter</x-btn>
            @if (request()->hasAny(['q', 'client_id', 'status']))
                <x-btn :href="route('projects.index')" variant="ghost">Reset</x-btn>
            @endif
        </div>
    </form>

    <x-table>
        <thead><tr>
            <th>Judul</th>
            <th>Klien</th>
            <th>Status</th>
            <th>Progress</th>
            <th>Deadline</th>
            <th class="text-right">Nilai</th>
            <th class="text-right">Aksi</th>
        </tr></thead>
        <tbody>
            @forelse ($projects as $project)
                @php $progress = $project->progressPercent(); @endphp
                <tr>
                    <td>
                        <a href="{{ route('projects.show', $project) }}" class="font-semibold text-slate-900 hover:text-brand-600 dark:text-white">{{ $project->title }}</a>
                    </td>
                    <td><a href="{{ route('clients.show', $project->client) }}" class="hover:text-brand-600">{{ $project->client?->name ?? '—' }}</a></td>
                    <td>
                        <x-badge variant="info">{{ $project->status->label() }}</x-badge>
                        @if ($project->isOverdue())
                            <x-badge variant="danger" :dot="true" class="ml-1">Overdue</x-badge>
                        @endif
                    </td>
                    <td>
                        <div class="flex items-center gap-2">
                            <div class="h-1.5 w-20 overflow-hidden rounded-full bg-slate-200 dark:bg-slate-700">
                                <div class="h-full rounded-full {{ $progress === 100 ? 'bg-green-500' : 'bg-brand-500' }}" style="width: {{ $progress }}%"></div>
                            </div>
                            <span class="text-xs text-slate-500">{{ $progress }}%</span>
                            <span class="text-xs text-slate-400">({{ $project->doneTasksCount() }}/{{ $project->tasksCount() }})</span>
                        </div>
                    </td>
                    <td class="{{ $project->isOverdue() ? 'font-semibold text-red-600' : '' }}">{{ tgl_id($project->deadline) }}</td>
                    <td class="text-right tabular-nums">{{ rupiah($project->value) }}</td>
                    <td class="whitespace-nowrap text-right">
                        <a href="{{ route('projects.edit', $project) }}" class="text-sm font-semibold text-brand-600 hover:underline">Ubah</a>
                        <form method="POST" action="{{ route('projects.destroy', $project) }}" class="inline" onsubmit="return confirm('Hapus project ini?')">
                            @csrf
                            @method('DELETE')
                            <button type="submit" class="ml-2 text-sm font-semibold text-rose-600 hover:underline">Hapus</button>
                        </form>
                    </td>
                </tr>
            @empty
                <tr><td colspan="7">
                    <x-empty-state title="Belum ada project" icon="folder-kanban"
                                   description="Buat project pertama untuk mulai mencatat tugas dan progres." />
                </td></tr>
            @endforelse
        </tbody>
    </x-table>

    @if ($projects->hasPages())
        <div class="mt-4">{{ $projects->links() }}</div>
    @endif
</x-card>
@endsection
