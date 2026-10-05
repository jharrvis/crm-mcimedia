@extends('layouts.app')

@section('title', 'Pengguna')

@section('content')
<div class="mb-4 flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
    <form method="GET" action="{{ route('users.index') }}" class="flex gap-2">
        <input name="q" value="{{ request('q') }}" placeholder="Cari nama / email…"
               class="w-64 rounded-lg border border-slate-300 px-3 py-2 text-sm dark:border-slate-700 dark:bg-slate-800">
        <button class="rounded-lg border border-slate-300 px-4 py-2 text-sm font-medium hover:bg-slate-50 dark:border-slate-700 dark:hover:bg-slate-800">Cari</button>
    </form>
    <a href="{{ route('users.create') }}"
       class="rounded-lg bg-brand-600 px-4 py-2 text-sm font-semibold text-white hover:bg-brand-700">Tambah pengguna</a>
</div>

<div class="overflow-x-auto rounded-xl border border-slate-200 bg-white dark:border-slate-800 dark:bg-slate-900">
    <table class="w-full text-sm">
        <thead>
            <tr class="text-left text-xs uppercase text-slate-500">
                <th class="px-4 py-3">Nama</th>
                <th class="px-4 py-3">Email</th>
                <th class="px-4 py-3">Role</th>
                <th class="px-4 py-3 text-right">Aksi</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($users as $user)
                <tr class="border-t border-slate-100 dark:border-slate-800">
                    <td class="px-4 py-3 font-medium">
                        {{ $user->name }}
                        @if ($user->is(auth()->user()))
                            <span class="ml-1 text-xs text-slate-500">(Anda)</span>
                        @endif
                    </td>
                    <td class="px-4 py-3 text-slate-500">{{ $user->email }}</td>
                    <td class="px-4 py-3">
                        @if ($user->role)
                            <span class="rounded-full bg-slate-200 px-2 py-0.5 text-xs font-medium text-slate-700 dark:bg-slate-800 dark:text-slate-300">{{ $user->role->label }}</span>
                        @else
                            <span class="rounded-full bg-brand-100 px-2 py-0.5 text-xs font-medium text-brand-700 dark:bg-brand-950 dark:text-brand-300">Administrator (tanpa role)</span>
                        @endif
                    </td>
                    <td class="px-4 py-3 text-right">
                        <a href="{{ route('users.edit', $user) }}" class="text-sm text-brand-600 hover:underline">Ubah</a>
                        @unless ($user->is(auth()->user()))
                            <form method="POST" action="{{ route('users.destroy', $user) }}" class="inline"
                                  onsubmit="return confirm('Hapus pengguna {{ $user->name }}?')">
                                @csrf
                                @method('DELETE')
                                <button class="ml-2 text-sm text-red-600 hover:underline">Hapus</button>
                            </form>
                        @endunless
                    </td>
                </tr>
            @empty
                <tr><td colspan="4" class="px-4 py-8 text-center text-slate-500">Belum ada pengguna.</td></tr>
            @endforelse
        </tbody>
    </table>
</div>

<div class="mt-4">{{ $users->links() }}</div>
@endsection
