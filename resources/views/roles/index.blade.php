@extends('layouts.app')

@section('title', 'Role & Hak Akses')

@section('content')
<x-page-header title="Role & Hak Akses" icon="shield-check"
               subtitle="Atur role dan hak akses per modul untuk setiap pengguna.">
    <x-btn :href="route('roles.create')" icon="plus">Tambah role</x-btn>
</x-page-header>

<x-card>
    <x-table>
        <thead><tr>
            <th>Role</th>
            <th>Hak akses</th>
            <th class="text-center">Pengguna</th>
            <th class="text-right">Aksi</th>
        </tr></thead>
        <tbody>
            @forelse ($roles as $role)
                @php $map = $role->permissionMap(); @endphp
                <tr>
                    <td>
                        <p class="font-semibold">{{ $role->label }}</p>
                        <p class="text-xs text-slate-400">{{ $role->name }}</p>
                    </td>
                    <td>
                        @if ($role->is_admin)
                            <x-badge variant="info">Administrator — semua modul</x-badge>
                        @else
                            @foreach (\App\Domains\Access\Enums\Module::cases() as $module)
                                @if (isset($map[$module->value]))
                                    <x-badge variant="slate" class="mr-1 mb-1">
                                        {{ $module->label() }} · {{ $map[$module->value] === 'manage' ? 'kelola' : 'lihat' }}
                                    </x-badge>
                                @endif
                            @endforeach
                            @if (empty($map))
                                <span class="text-xs text-slate-400">Tidak ada akses modul</span>
                            @endif
                        @endif
                    </td>
                    <td class="text-center">{{ $role->users_count }}</td>
                    <td class="whitespace-nowrap text-right">
                        <a href="{{ route('roles.edit', $role) }}" class="text-sm font-semibold text-brand-600 hover:underline">Ubah</a>
                        @unless ($role->is_admin)
                            <form method="POST" action="{{ route('roles.destroy', $role) }}" class="ml-2 inline"
                                  onsubmit="return confirm('Hapus role {{ $role->label }}?')">
                                @csrf
                                @method('DELETE')
                                <button type="submit" class="text-sm font-semibold text-rose-600 hover:underline">Hapus</button>
                            </form>
                        @endunless
                    </td>
                </tr>
            @empty
                <tr><td colspan="4">
                    <x-empty-state title="Belum ada role" icon="shield-check"
                                   description="Buat role pertama untuk membagi hak akses per modul." />
                </td></tr>
            @endforelse
        </tbody>
    </x-table>
</x-card>
@endsection
