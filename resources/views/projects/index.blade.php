@extends('layouts.app')

@section('title', 'Project')

@section('content')
<div class="mb-4 flex items-center justify-between">
    <form method="GET" action="{{ route('projects.index') }}" class="flex flex-wrap gap-2">
        <input name="q" value="{{ request('q') }}" placeholder="Cari judul…"
               class="rounded-lg border border-slate-300 px-3 py-2 text-sm dark:border-slate-700 dark:bg-slate-800">
        <select name="client_id" class="rounded-lg border border-slate-300 px-3 py-2 text-sm dark:border-slate-700 dark:bg-slate-800">
            <option value="">Semua klien</option>
            @foreach ($clients as $client)
                <option value="{{ $client->id }}" @selected(request('client_id') == $client->id)>{{ $client->name }}</option>
            @endforeach
        </select>
        <select name="status" class="rounded-lg border border-slate-300 px-3 py-2 text-sm dark:border-slate-700 dark:bg-slate-800">
            <option value="">Semua status</option>
            @foreach ($statuses as $status)
                <option value="{{ $status->value }}" @selected(request('status') === $status->value)>{{ $status->label() }}</option>
            @endforeach
        </select>
        <button class="rounded-lg border border-slate-300 px-4 py-2 text-sm hover:bg-slate-50 dark:border-slate-700 dark:hover:bg-slate-800">Filter</button>
        @if (request()->hasAny(['q', 'client_id', 'status']))
            <a href="{{ route('projects.index') }}" class="rounded-lg px-3 py-2 text-sm text-slate-500 hover:underline">Reset</a>
        @endif
    </form>
    <a href="{{ route('projects.create') }}"
       class="shrink-0 rounded-lg bg-brand-600 px-4 py-2 text-sm font-semibold text-white hover:bg-brand-700">Tambah project</a>
</div>

<div class="rounded-xl border border-slate-200 bg-white p-5 dark:border-slate-800 dark:bg-slate-900">
    <div class="overflow-x-auto">
        <table class="w-full text-sm">
            <thead><tr class="text-left text-xs uppercase text-slate-500">
                <th class="py-2 pr-4">Judul</th><th class="py-2 pr-4">Klien</th><th class="py-2 pr-4">Status</th>
                <th class="py-2 pr-4">Progress</th><th class="py-2 pr-4">Deadline</th>
                <th class="py-2 pr-4 text-right">Nilai</th><th class="py-2">Aksi</th>
            </tr></thead>
            <tbody>
                @forelse ($projects as $project)
                    @php $progress = $project->progressPercent(); @endphp
                    <tr class="border-t border-slate-100 dark:border-slate-800">
                        <td class="py-2 pr-4"><a href="{{ route('projects.show', $project) }}" class="font-medium text-brand-600 hover:underline">{{ $project->title }}</a></td>
                        <td class="py-2 pr-4"><a href="{{ route('clients.show', $project->client) }}" class="hover:text-brand-600">{{ $project->client?->name ?? '—' }}</a></td>
                        <td class="py-2 pr-4">
                            <span class="rounded-full bg-brand-100 px-2 py-0.5 text-xs font-medium text-brand-700 dark:bg-brand-950 dark:text-brand-300">{{ $project->status->label() }}</span>
                            @if ($project->isOverdue())
                                <span class="ml-1 rounded-full bg-red-100 px-2 py-0.5 text-xs font-medium text-red-700 dark:bg-red-950 dark:text-red-300">Overdue</span>
                            @endif
                        </td>
                        <td class="py-2 pr-4">
                            <div class="flex items-center gap-2">
                                <div class="h-1.5 w-20 overflow-hidden rounded-full bg-slate-200 dark:bg-slate-700">
                                    <div class="h-full rounded-full {{ $progress === 100 ? 'bg-green-500' : 'bg-brand-500' }}" style="width: {{ $progress }}%"></div>
                                </div>
                                <span class="text-xs text-slate-500">{{ $progress }}%</span>
                                <span class="text-xs text-slate-400">({{ $project->doneTasksCount() }}/{{ $project->tasksCount() }})</span>
                            </div>
                        </td>
                        <td class="py-2 pr-4 {{ $project->isOverdue() ? 'font-semibold text-red-600' : '' }}">{{ tgl_id($project->deadline) }}</td>
                        <td class="py-2 pr-4 text-right">{{ rupiah($project->value) }}</td>
                        <td class="whitespace-nowrap py-2">
                            <a href="{{ route('projects.edit', $project) }}" class="text-sm text-brand-600 hover:underline">Ubah</a>
                            <span class="text-slate-300"> · </span>
                            <form method="POST" action="{{ route('projects.destroy', $project) }}" class="inline" onsubmit="return confirm('Hapus project ini?')">
                                @csrf @method('DELETE')
                                <button class="text-sm text-red-600 hover:underline">Hapus</button>
                            </form>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="7" class="py-6 text-center text-slate-500">Belum ada project.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
    <div class="mt-4">{{ $projects->links() }}</div>
</div>
@endsection
