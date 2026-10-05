@extends('layouts.app')

@section('title', 'Ubah Insiden Keamanan')

@section('content')
<form method="POST" action="{{ route('security.incidents.update', $incident) }}" class="max-w-3xl space-y-4 rounded-xl border border-slate-200 bg-white p-6 dark:border-slate-800 dark:bg-slate-900">
    @csrf
    @method('PUT')
    @include('security.incidents._form')
    <div class="flex gap-2 pt-2">
        <button class="rounded-lg bg-brand-600 px-4 py-2 text-sm font-semibold text-white hover:bg-brand-700">Simpan perubahan</button>
        <a href="{{ route('security.incidents.index') }}" class="rounded-lg border border-slate-300 px-4 py-2 text-sm font-medium hover:bg-slate-50 dark:border-slate-700 dark:hover:bg-slate-800">Batal</a>
    </div>
</form>
@endsection
