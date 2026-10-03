@php
    $inputClass = 'w-full rounded-lg border border-slate-300 px-3 py-2 text-sm dark:border-slate-700 dark:bg-slate-800';
@endphp

<div class="grid gap-4 sm:grid-cols-2">
    <div>
        <label class="mb-1 block text-sm font-medium">Nama kategori <span class="text-red-600">*</span></label>
        <input name="name" required value="{{ old('name', $category?->name) }}" placeholder="mis. Hosting" class="{{ $inputClass }}">
        @error('name')<p class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror
    </div>
    <div>
        <label class="mb-1 block text-sm font-medium">Urutan tampil</label>
        <input name="sort_order" type="number" min="0" step="1" value="{{ old('sort_order', $category?->sort_order ?? 0) }}" class="{{ $inputClass }}">
        @error('sort_order')<p class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror
    </div>
    <div class="sm:col-span-2">
        <label class="mb-1 block text-sm font-medium">Deskripsi</label>
        <input name="description" value="{{ old('description', $category?->description) }}" placeholder="opsional" class="{{ $inputClass }}">
        @error('description')<p class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror
    </div>
</div>
