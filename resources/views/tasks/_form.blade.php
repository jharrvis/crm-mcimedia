@php
    $task = $task ?? null;
    $isEdit = (bool) $task;
@endphp
<div class="space-y-4">
    <div>
        <label class="mb-1 block text-sm font-medium">Judul tugas</label>
        <input name="title" required value="{{ old('title', $task?->title) }}"
               class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm dark:border-slate-700 dark:bg-slate-800">
        @error('title')<p class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror
    </div>
    <div>
        <label class="mb-1 block text-sm font-medium">Deskripsi</label>
        <textarea name="description" rows="3"
                  class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm dark:border-slate-700 dark:bg-slate-800">{{ old('description', $task?->description) }}</textarea>
        @error('description')<p class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror
    </div>
    <div class="grid gap-4 sm:grid-cols-2">
        <div>
            <label class="mb-1 block text-sm font-medium">Klien (opsional)</label>
            <select name="client_id"
                    class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm dark:border-slate-700 dark:bg-slate-800">
                <option value="">— Tanpa klien —</option>
                @foreach ($clients as $client)
                    <option value="{{ $client->id }}" @selected(old('client_id', $task?->client_id) == $client->id)>{{ $client->name }}</option>
                @endforeach
            </select>
            @error('client_id')<p class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror
        </div>
        <div>
            <label class="mb-1 block text-sm font-medium">Project (opsional)</label>
            <select name="project_id"
                    class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm dark:border-slate-700 dark:bg-slate-800">
                <option value="">— Tanpa project —</option>
                @foreach ($projects as $project)
                    <option value="{{ $project->id }}" @selected(old('project_id', $task?->project_id) == $project->id)>{{ $project->title }}</option>
                @endforeach
            </select>
            @error('project_id')<p class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror
        </div>
        <div>
            <label class="mb-1 block text-sm font-medium">Penanggung jawab (opsional)</label>
            <select name="assigned_user_id"
                    class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm dark:border-slate-700 dark:bg-slate-800">
                <option value="">— Belum ditentukan —</option>
                @foreach ($users as $user)
                    <option value="{{ $user->id }}" @selected(old('assigned_user_id', $task?->assigned_user_id) == $user->id)>{{ $user->name }}</option>
                @endforeach
            </select>
            @error('assigned_user_id')<p class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror
        </div>
        <div>
            <label class="mb-1 block text-sm font-medium">Prioritas</label>
            <select name="priority" required
                    class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm dark:border-slate-700 dark:bg-slate-800">
                @foreach ($priorities as $priority)
                    <option value="{{ $priority->value }}" @selected(old('priority', $task?->priority?->value ?? 'medium') === $priority->value)>{{ $priority->label() }}</option>
                @endforeach
            </select>
            @error('priority')<p class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror
        </div>
        <div>
            <label class="mb-1 block text-sm font-medium">Due date</label>
            <input name="due_date" type="date" value="{{ old('due_date', $task?->due_date?->format('Y-m-d')) }}"
                   class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm dark:border-slate-700 dark:bg-slate-800">
            @error('due_date')<p class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror
        </div>
        @if ($isEdit)
            <div>
                <label class="mb-1 block text-sm font-medium">Status</label>
                <select name="status"
                        class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm dark:border-slate-700 dark:bg-slate-800">
                    @foreach ($statuses as $status)
                        <option value="{{ $status->value }}" @selected(old('status', $task->status->value) === $status->value)>{{ $status->label() }}</option>
                    @endforeach
                </select>
                @error('status')<p class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror
            </div>
        @endif
    </div>
    <div class="flex gap-2 pt-2">
        <button class="rounded-lg bg-indigo-600 px-4 py-2 text-sm font-semibold text-white hover:bg-indigo-700">
            {{ $isEdit ? 'Simpan perubahan' : 'Tambah tugas' }}
        </button>
        <a href="{{ route('tasks.index') }}"
           class="rounded-lg border border-slate-300 px-4 py-2 text-sm hover:bg-slate-50 dark:border-slate-700 dark:hover:bg-slate-800">Batal</a>
    </div>
</div>
