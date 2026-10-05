@extends('layouts.app')

@section('title', 'Ubah Server Hestia')

@section('content')
<div class="mb-4">
    <a href="{{ route('hestia.servers.index') }}" class="text-sm text-brand-600 hover:underline">&larr; Kembali ke daftar server</a>
</div>

<div class="mb-6 rounded-xl border border-slate-200 bg-white p-6 dark:border-slate-800 dark:bg-slate-900">
    <h1 class="mb-1 text-lg font-bold">Ubah Server {{ $server->name }}</h1>
    <p class="mb-4 text-sm text-slate-500">
        Field kredensial yang dikosongkan akan mempertahankan nilai lama yang sudah tersimpan.
    </p>

    <form method="POST" action="{{ route('hestia.servers.update', $server) }}">
        @csrf
        @method('PUT')
        @include('hestia.servers._form')

        <div class="mt-6 flex items-center gap-2">
            <button class="rounded-lg bg-brand-600 px-4 py-2 text-sm font-semibold text-white hover:bg-brand-700">
                Simpan perubahan
            </button>
            <a href="{{ route('hestia.servers.index') }}"
               class="rounded-lg border border-slate-300 px-4 py-2 text-sm font-semibold text-slate-700 hover:bg-slate-50 dark:border-slate-700 dark:text-slate-200">
                Batal
            </a>
        </div>
    </form>
</div>
@endsection