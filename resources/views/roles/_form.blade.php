@php
    $selected = old('permissions', $role->permissionMap());
    $selected = is_array($selected) ? $selected : [];
@endphp

<div class="grid gap-4 sm:grid-cols-2">
    <x-input name="name" label="Nama role" :required="true" :value="$role->name"
             placeholder="mis. operator" hint="Huruf kecil, angka, tanda hubung. Dipakai sebagai kode unik." />
    <x-input name="label" label="Label" :required="true" :value="$role->label" placeholder="mis. Operator" />
</div>
<x-input name="description" label="Deskripsi" :value="$role->description" />

<div>
    <p class="mb-2 text-sm font-medium">Hak akses per modul</p>
    <x-card :padded="false">
        <x-table>
            <thead><tr>
                <th>Modul</th>
                <th class="w-48">Akses</th>
            </tr></thead>
            <tbody>
                @foreach ($modules as $module)
                    <tr>
                        <td>{{ $module->label() }}</td>
                        <td>
                            <x-input name="permissions[{{ $module->value }}]" type="select" class="mb-0"
                                     :options="$levelOptions"
                                     :value="$selected[$module->value] ?? ''"
                                     data-supports-manage="{{ $module->supportsManage() ? '1' : '0' }}" />
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </x-table>
    </x-card>
    @error('permissions')<p class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror
</div>

@if (auth()->user()->isAdmin())
    <label class="flex items-center gap-2 text-sm">
        <input type="checkbox" name="is_admin" value="1" class="rounded border-slate-300 text-brand-600 focus:ring-brand-500"
               @checked(old('is_admin', $role->is_admin)) @disabled($role->is_admin)>
        Role administrator — seluruh modul terbuka, mengabaikan pengaturan di atas.
    </label>
    @if ($role->is_admin)
        <input type="hidden" name="is_admin" value="1">
        <p class="text-xs text-slate-400">Flag administrator bersifat permanen agar sistem tidak terkunci.</p>
    @endif
@endif
