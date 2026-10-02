@extends('layouts.app')

@section('title', 'Role & Hak Akses')

@section('content')
<div class="mb-4 flex items-center justify-between">
    <p class="text-sm text-slate-500">Atur role dan hak akses per modul untuk setiap pengguna.</p>
    <a href="{{ route('roles.create') }}"
       class="rounded-lg bg-indigo-600 px-4 py-2 text-sm font-semibold text-white hover:bg-indigo-700">Tambah role</a>
</div>

<div class="overflow-x-auto rounded-xl border border-slate-200 bg-white dark:border-slate-800 dark:bg-slate-900">
    <table class="w-full text-sm">
        <thead>
            <tr class="text-left text-xs uppercase text-slate-500">
                <th class="px-4 py-3">Role</th>
                <th class="px-4 py-3">Hak akses</th>
                <th class="px-4 py-3 text-center">Pengguna</th>
                <th class="px-4 py-3 text-right">Aksi</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($roles as $role)
                <tr class="border-t border-slate-100 dark:border-slate-800">
                    <td class="px-4 py-3">
                        <p class="font-medium">{{ $role->label }}</p>
                        <p class="text-xs text-slate-500">{{ $role->name }}</p>
                    </td>
                    <td class="px-4 py-3">
                        @if ($role->is_admin)
                            <span class="rounded-full bg-indigo-100 px-2 py-0.5 text-xs font-medium text-indigo-700 dark:bg-indigo-950 dark:text-indigo-300">Administrator — semua modul</span>
                        @else
                            @php $map = $role->permissionMap(); @endphp
                            @foreach (\App\Domains\Access\Enums\Module::cases() as $module)
                                @if (isset($map[$module->value]))
                                    <span class="mr-1 inline-block rounded bg-slate-100 px-2 py-0.5 text-xs dark:bg-slate-800">
                                        {{ $module->label() }} · {{ $map[$module->value] === 'manage' ? 'kelola' : 'lihat' }}
                                    </span>
                                @endif
                            @endforeach
                            @if (empty($map))
                                <span class="text-xs text-slate-500">Tidak ada akses modul</span>
                            @endif
                        @endif
                    </td>
                    <td class="px-4 py-3 text-center">{{ $role->users_count }}</td>
                    <td class="px-4 py-3 text-right">
                        <a href="{{ route('roles.edit', $role) }}" class="text-sm text-indigo-600 hover:underline">Ubah</a>
                        @unless ($role->is_admin)
                            <form method="POST" action="{{ route('roles.destroy', $role) }}" class="inline"
                                  onsubmit="return confirm('Hapus role {{ $role->label }}?')">
                                @csrf
                                @method('DELETE')
                                <button class="ml-2 text-sm text-red-600 hover:underline">Hapus</button>
                            </form>
                        @endunless
                    </td>
                </tr>
            @empty
                <tr><td colspan="4" class="px-4 py-8 text-center text-slate-500">Belum ada role.</td></tr>
            @endforelse
        </tbody>
    </table>
</div>
@endsection
