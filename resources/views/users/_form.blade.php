@php
    $roleOptions = ['' => '— Pilih role —'] + collect($roles ?? [])->mapWithKeys(fn ($r) => [$r->id => $r->label . ($r->is_admin ? ' (akses penuh)' : '')])->all();
@endphp

<x-input name="name" label="Nama" :required="true" :value="$user->name" />
<x-input name="email" label="Email" type="email" :required="true" :value="$user->email" />
<x-input name="role_id" label="Role" type="select" :required="true" :options="$roleOptions"
         :value="(string) old('role_id', $user->role_id)" />

<div class="grid gap-4 sm:grid-cols-2">
    <x-input name="password" label="Kata sandi{{ $user->exists ? '' : ' *' }}" type="password" autocomplete="new-password"
             hint="{{ $user->exists ? 'Kosongkan bila tidak ingin mengubah.' : 'Minimal 8 karakter.' }}" />
    <x-input name="password_confirmation" label="Ulangi kata sandi" type="password" autocomplete="new-password" />
</div>
