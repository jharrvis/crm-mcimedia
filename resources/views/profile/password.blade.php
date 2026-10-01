@extends('layouts.app')

@section('title', 'Ubah Kata Sandi')

@section('content')
<div class="max-w-md rounded-xl border border-slate-200 bg-white p-6 dark:border-slate-800 dark:bg-slate-900">
    <form method="POST" action="{{ route('profile.password.update') }}" class="space-y-4">
        @csrf
        @method('PUT')
        <div>
            <label class="mb-1 block text-sm font-medium">Kata sandi saat ini</label>
            <input name="current_password" type="password" required
                   class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm dark:border-slate-700 dark:bg-slate-800">
            @error('current_password')<p class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror
        </div>
        <div>
            <label class="mb-1 block text-sm font-medium">Kata sandi baru (min. 8 karakter)</label>
            <input name="password" type="password" required
                   class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm dark:border-slate-700 dark:bg-slate-800">
            @error('password')<p class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror
        </div>
        <div>
            <label class="mb-1 block text-sm font-medium">Konfirmasi kata sandi baru</label>
            <input name="password_confirmation" type="password" required
                   class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm dark:border-slate-700 dark:bg-slate-800">
        </div>
        <button class="rounded-lg bg-indigo-600 px-4 py-2 text-sm font-semibold text-white hover:bg-indigo-700">
            Simpan
        </button>
    </form>
</div>
@endsection
