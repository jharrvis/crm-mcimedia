@php
    $input = 'w-full rounded-lg border border-slate-300 px-3 py-2 text-sm dark:border-slate-700 dark:bg-slate-800';
    $selected = old('permissions', $role->permissionMap());
    $selected = is_array($selected) ? $selected : [];
@endphp
<div class="grid gap-4 sm:grid-cols-2">
    <div>
        <label class="mb-1 block text-sm font-medium">Nama role <span class="text-red-600">*</span></label>
        <input name="name" value="{{ old('name', $role->name) }}" required placeholder="mis. operator" class="{{ $input }}">
        <p class="mt-1 text-xs text-slate-500">Huruf kecil, angka, tanda hubung. Dipakai sebagai kode unik.</p>
        @error('name')<p class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror
    </div>
    <div>
        <label class="mb-1 block text-sm font-medium">Label <span class="text-red-600">*</span></label>
        <input name="label" value="{{ old('label', $role->label) }}" required placeholder="mis. Operator" class="{{ $input }}">
        @error('label')<p class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror
    </div>
</div>
<div>
    <label class="mb-1 block text-sm font-medium">Deskripsi</label>
    <input name="description" value="{{ old('description', $role->description) }}" class="{{ $input }}">
    @error('description')<p class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror
</div>

<div>
    <p class="mb-2 text-sm font-medium">Hak akses per modul</p>
    <div class="overflow-hidden rounded-lg border border-slate-200 dark:border-slate-700">
        <table class="w-full text-sm">
            <thead class="bg-slate-50 dark:bg-slate-800">
                <tr class="text-left text-xs uppercase text-slate-500">
                    <th class="px-3 py-2">Modul</th>
                    <th class="px-3 py-2 w-48">Akses</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($modules as $module)
                    <tr class="border-t border-slate-100 dark:border-slate-800">
                        <td class="px-3 py-2">{{ $module->label() }}</td>
                        <td class="px-3 py-2">
                            <select name="permissions[{{ $module->value }}]" class="{{ $input }}">
                                <option value="">— Tidak ada —</option>
                                @foreach ($levels as $level)
                                    @if ($level->value === 'manage' && ! $module->supportsManage())
                                        @continue
                                    @endif
                                    <option value="{{ $level->value }}"
                                        @selected(($selected[$module->value] ?? '') === $level->value)>
                                        {{ $level->label() }}
                                    </option>
                                @endforeach
                            </select>
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
    @error('permissions')<p class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror
</div>

@if (auth()->user()->isAdmin())
    <label class="flex items-center gap-2 text-sm">
        <input type="checkbox" name="is_admin" value="1" class="rounded"
               @checked(old('is_admin', $role->is_admin)) @disabled($role->is_admin)>
        Role administrator — seluruh modul terbuka, mengabaikan pengaturan di atas.
    </label>
    @if ($role->is_admin)
        <input type="hidden" name="is_admin" value="1">
        <p class="text-xs text-slate-500">Flag administrator bersifat permanen agar sistem tidak terkunci.</p>
    @endif
@endif
