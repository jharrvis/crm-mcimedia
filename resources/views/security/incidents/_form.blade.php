@php
    $input = 'w-full rounded-lg border border-slate-300 px-3 py-2 text-sm dark:border-slate-700 dark:bg-slate-800';
    $occurredAt = old('occurred_at', optional($incident->occurred_at)->format('Y-m-d\TH:i'));
@endphp
<div class="grid gap-4 sm:grid-cols-2">
    <div>
        <label class="mb-1 block text-sm font-medium">Klien <span class="text-red-600">*</span></label>
        <select name="client_id" required class="{{ $input }}">
            <option value="">— Pilih klien —</option>
            @foreach ($clients as $c)
                <option value="{{ $c->id }}" @selected((int) old('client_id', $incident->client_id) === $c->id)>{{ $c->name }}</option>
            @endforeach
        </select>
        @error('client_id')<p class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror
    </div>
    <div>
        <label class="mb-1 block text-sm font-medium">Waktu kejadian <span class="text-red-600">*</span></label>
        <input type="datetime-local" name="occurred_at" value="{{ $occurredAt }}" required class="{{ $input }}">
        @error('occurred_at')<p class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror
    </div>
    <div>
        <label class="mb-1 block text-sm font-medium">Tingkat keparahan <span class="text-red-600">*</span></label>
        <select name="severity" required class="{{ $input }}">
            @foreach ($severities as $s)
                <option value="{{ $s->value }}" @selected(old('severity', $incident->severity?->value) === $s->value)>{{ $s->label() }}</option>
            @endforeach
        </select>
        @error('severity')<p class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror
    </div>
    <div>
        <label class="mb-1 block text-sm font-medium">Sumber temuan <span class="text-red-600">*</span></label>
        <select name="source" required class="{{ $input }}">
            @foreach ($sources as $s)
                <option value="{{ $s->value }}" @selected(old('source', $incident->source?->value) === $s->value)>{{ $s->label() }}</option>
            @endforeach
        </select>
        @error('source')<p class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror
    </div>
</div>
<div>
    <label class="mb-1 block text-sm font-medium">Judul <span class="text-red-600">*</span></label>
    <input name="title" value="{{ old('title', $incident->title) }}" required class="{{ $input }}">
    @error('title')<p class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror
</div>
<div>
    <label class="mb-1 block text-sm font-medium">Deskripsi</label>
    <textarea name="description" rows="4" class="{{ $input }}">{{ old('description', $incident->description) }}</textarea>
    @error('description')<p class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror
</div>
<div>
    <label class="mb-1 block text-sm font-medium">Status <span class="text-red-600">*</span></label>
    <select name="status" required class="{{ $input }}">
        @foreach ($statuses as $s)
            <option value="{{ $s->value }}" @selected(old('status', $incident->status?->value ?? 'open') === $s->value)>{{ $s->label() }}</option>
        @endforeach
    </select>
    <p class="mt-1 text-xs text-slate-500">Saat status diubah ke “Selesai”, waktu penyelesaian dicatat otomatis.</p>
    @error('status')<p class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror
</div>
