@php $input = 'w-full rounded-lg border border-slate-300 px-3 py-2 text-sm dark:border-slate-700 dark:bg-slate-800'; @endphp
<div>
    <label class="mb-1 block text-sm font-medium">Nama <span class="text-red-600">*</span></label>
    <input name="name" value="{{ old('name', $user->name) }}" required class="{{ $input }}">
    @error('name')<p class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror
</div>
<div>
    <label class="mb-1 block text-sm font-medium">Email <span class="text-red-600">*</span></label>
    <input name="email" type="email" value="{{ old('email', $user->email) }}" required class="{{ $input }}">
    @error('email')<p class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror
</div>
<div>
    <label class="mb-1 block text-sm font-medium">Role <span class="text-red-600">*</span></label>
    <select name="role_id" required class="{{ $input }}">
        <option value="">— Pilih role —</option>
        @foreach ($roles as $role)
            <option value="{{ $role->id }}" @selected((string) old('role_id', $user->role_id) === (string) $role->id)>
                {{ $role->label }}{{ $role->is_admin ? ' (akses penuh)' : '' }}
            </option>
        @endforeach
    </select>
    @error('role_id')<p class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror
</div>
<div class="grid gap-4 sm:grid-cols-2">
    <div>
        <label class="mb-1 block text-sm font-medium">
            Kata sandi @if (! $user->exists)<span class="text-red-600">*</span>@endif
        </label>
        <input name="password" type="password" autocomplete="new-password" class="{{ $input }}">
        <p class="mt-1 text-xs text-slate-500">
            {{ $user->exists ? 'Kosongkan bila tidak ingin mengubah.' : 'Minimal 8 karakter.' }}
        </p>
        @error('password')<p class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror
    </div>
    <div>
        <label class="mb-1 block text-sm font-medium">Ulangi kata sandi</label>
        <input name="password_confirmation" type="password" autocomplete="new-password" class="{{ $input }}">
    </div>
</div>
