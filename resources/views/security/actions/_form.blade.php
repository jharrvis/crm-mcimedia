@php $input = 'w-full rounded-lg border border-slate-300 px-3 py-2 text-sm dark:border-slate-700 dark:bg-slate-800'; @endphp
<div class="grid gap-4 sm:grid-cols-2">
    <div>
        <x-client-select
            :clients="$clients"
            :selected="old('client_id', $action->client_id)"
            label="Klien"
            required />
    </div>
    <div>
        <label class="mb-1 block text-sm font-medium">Tanggal tindakan <span class="text-red-600">*</span></label>
        <input type="date" name="acted_at" value="{{ old('acted_at', optional($action->acted_at)->format('Y-m-d')) }}" required class="{{ $input }}">
        @error('acted_at')<p class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror
    </div>
</div>
<div>
    <label class="mb-1 block text-sm font-medium">Dikerjakan oleh</label>
    <input name="performed_by" value="{{ old('performed_by', $action->performed_by) }}" class="{{ $input }}">
    @error('performed_by')<p class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror
</div>
<div>
    <label class="mb-1 block text-sm font-medium">Tindakan <span class="text-red-600">*</span></label>
    <textarea name="action" rows="3" required class="{{ $input }}">{{ old('action', $action->action) }}</textarea>
    @error('action')<p class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror
</div>
<div>
    <label class="mb-1 block text-sm font-medium">Hasil</label>
    <textarea name="result" rows="3" class="{{ $input }}">{{ old('result', $action->result) }}</textarea>
    @error('result')<p class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror
</div>
