@php $input = 'w-full rounded-lg border border-slate-300 px-3 py-2 text-sm dark:border-slate-700 dark:bg-slate-800'; @endphp
<div>
    <label class="mb-1 block text-sm font-medium">Nama <span class="text-red-600">*</span></label>
    <input name="name" value="{{ old('name', $contact?->name) }}" required class="{{ $input }}">
    @error('name')<p class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror
</div>
<div>
    <label class="mb-1 block text-sm font-medium">Peran / jabatan</label>
    <input name="role" value="{{ old('role', $contact?->role) }}" class="{{ $input }}">
    @error('role')<p class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror
</div>
<div class="grid gap-4 sm:grid-cols-2">
    <div>
        <label class="mb-1 block text-sm font-medium">Email</label>
        <input name="email" type="email" value="{{ old('email', $contact?->email) }}" class="{{ $input }}">
        @error('email')<p class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror
    </div>
    <div>
        <label class="mb-1 block text-sm font-medium">WhatsApp</label>
        <input name="whatsapp" value="{{ old('whatsapp', $contact?->whatsapp) }}" placeholder="628…" class="{{ $input }}">
        @error('whatsapp')<p class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror
    </div>
</div>
