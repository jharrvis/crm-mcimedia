@php $input = 'w-full rounded-lg border border-slate-300 px-3 py-2 text-sm dark:border-slate-700 dark:bg-slate-800'; @endphp
<div>
    <label class="mb-1 block text-sm font-medium">Nama usaha <span class="text-red-600">*</span></label>
    <input name="name" value="{{ old('name', $client?->name) }}" required class="{{ $input }}">
    @error('name')<p class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror
</div>
<div>
    <label class="mb-1 block text-sm font-medium">Nama kontak utama</label>
    <input name="contact_name" value="{{ old('contact_name', $client?->contact_name) }}" class="{{ $input }}">
    @error('contact_name')<p class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror
</div>
<div class="grid gap-4 sm:grid-cols-2">
    <div>
        <label class="mb-1 block text-sm font-medium">Email</label>
        <input name="email" type="email" value="{{ old('email', $client?->email) }}" class="{{ $input }}">
        @error('email')<p class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror
    </div>
    <div>
        <label class="mb-1 block text-sm font-medium">WhatsApp</label>
        <input name="whatsapp" value="{{ old('whatsapp', $client?->whatsapp) }}" placeholder="628…" class="{{ $input }}">
        @error('whatsapp')<p class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror
    </div>
</div>
<div>
    <label class="mb-1 block text-sm font-medium">Alamat</label>
    <textarea name="address" rows="2" class="{{ $input }}">{{ old('address', $client?->address) }}</textarea>
    @error('address')<p class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror
</div>
<div>
    <label class="mb-1 block text-sm font-medium">Catatan</label>
    <textarea name="notes" rows="3" class="{{ $input }}">{{ old('notes', $client?->notes) }}</textarea>
    @error('notes')<p class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror
</div>
<label class="flex items-center gap-2 text-sm">
    <input type="checkbox" name="is_active" value="1" @checked(old('is_active', $client?->is_active ?? true)) class="rounded">
    Klien aktif
</label>
