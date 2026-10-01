@php
    $project = $project ?? null;
    $selectedClient = old('client_id', $project?->client_id);
    $selectedStatus = old('status', $project?->status?->value ?? 'new');
@endphp
<div class="space-y-4">
    <div>
        <label class="mb-1 block text-sm font-medium">Klien</label>
        <select name="client_id" required
                class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm dark:border-slate-700 dark:bg-slate-800">
            <option value="">— Pilih klien —</option>
            @foreach ($clients as $client)
                <option value="{{ $client->id }}" @selected($selectedClient == $client->id)>{{ $client->name }}</option>
            @endforeach
        </select>
        @error('client_id')<p class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror
    </div>
    <div>
        <label class="mb-1 block text-sm font-medium">Judul project</label>
        <input name="title" required value="{{ old('title', $project?->title) }}"
               class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm dark:border-slate-700 dark:bg-slate-800">
        @error('title')<p class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror
    </div>
    <div>
        <label class="mb-1 block text-sm font-medium">Deskripsi</label>
        <textarea name="description" rows="4"
                  class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm dark:border-slate-700 dark:bg-slate-800">{{ old('description', $project?->description) }}</textarea>
        @error('description')<p class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror
    </div>
    <div class="grid gap-4 sm:grid-cols-3">
        <div>
            <label class="mb-1 block text-sm font-medium">Deadline</label>
            <input name="deadline" type="date" value="{{ old('deadline', $project?->deadline?->format('Y-m-d')) }}"
                   class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm dark:border-slate-700 dark:bg-slate-800">
            @error('deadline')<p class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror
        </div>
        <div>
            <label class="mb-1 block text-sm font-medium">Status</label>
            <select name="status" required
                    class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm dark:border-slate-700 dark:bg-slate-800">
                @foreach ($statuses as $status)
                    <option value="{{ $status->value }}" @selected($selectedStatus === $status->value)>{{ $status->label() }}</option>
                @endforeach
            </select>
            @error('status')<p class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror
        </div>
        <div>
            <label class="mb-1 block text-sm font-medium">Nilai project (Rp)</label>
            <input name="value" type="number" min="0" step="1" value="{{ old('value', $project?->value ?? 0) }}"
                   class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm dark:border-slate-700 dark:bg-slate-800">
            @error('value')<p class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror
        </div>
    </div>
    <div class="flex gap-2 pt-2">
        <button class="rounded-lg bg-indigo-600 px-4 py-2 text-sm font-semibold text-white hover:bg-indigo-700">
            {{ $project ? 'Simpan perubahan' : 'Tambah project' }}
        </button>
        <a href="{{ $project ? route('projects.show', $project) : route('projects.index') }}"
           class="rounded-lg border border-slate-300 px-4 py-2 text-sm hover:bg-slate-50 dark:border-slate-700 dark:hover:bg-slate-800">Batal</a>
    </div>
</div>
