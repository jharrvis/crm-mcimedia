@extends('layouts.app')

@section('title', 'Pengguna')

@section('content')
<x-page-header title="Pengguna" icon="users"
               subtitle="Akun internal CRM dan hak akses per role.">
    <x-btn :href="route('users.create')" icon="plus">Tambah pengguna</x-btn>
</x-page-header>

<x-card>
    <form method="GET" action="{{ route('users.index') }}" class="mb-4 flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
        <div class="flex gap-2">
            <x-input name="q" placeholder="Cari nama / email…" :value="request('q')" class="mb-0 w-64" />
            <x-btn type="submit" variant="dark" icon="search">Cari</x-btn>
        </div>
    </form>

    <x-table>
        <thead><tr>
            <th>Nama</th>
            <th>Email</th>
            <th>Role</th>
            <th class="text-right">Aksi</th>
        </tr></thead>
        <tbody>
            @forelse ($users as $user)
                <tr>
                    <td class="font-medium">
                        {{ $user->name }}
                        @if ($user->is(auth()->user()))
                            <span class="ml-1 text-xs text-slate-400">(Anda)</span>
                        @endif
                    </td>
                    <td class="text-slate-400">{{ $user->email }}</td>
                    <td>
                        @if ($user->role)
                            <x-badge variant="slate">{{ $user->role->label }}</x-badge>
                        @else
                            <x-badge variant="info">Administrator (tanpa role)</x-badge>
                        @endif
                    </td>
                    <td class="whitespace-nowrap text-right">
                        <a href="{{ route('users.edit', $user) }}" class="text-sm font-semibold text-brand-600 hover:underline">Ubah</a>
                        @unless ($user->is(auth()->user()))
                            <form method="POST" action="{{ route('users.destroy', $user) }}" class="ml-2 inline"
                                  onsubmit="return confirm('Hapus pengguna {{ $user->name }}?')">
                                @csrf
                                @method('DELETE')
                                <button type="submit" class="text-sm font-semibold text-rose-600 hover:underline">Hapus</button>
                            </form>
                        @endunless
                    </td>
                </tr>
            @empty
                <tr><td colspan="4">
                    <x-empty-state title="Belum ada pengguna" icon="users"
                                   description="Tambahkan pengguna pertama untuk berbagi akses CRM." />
                </td></tr>
            @endforelse
        </tbody>
    </x-table>

    @if ($users->hasPages())
        <div class="mt-4">{{ $users->links() }}</div>
    @endif
</x-card>
@endsection
