@extends('layouts.app')

@section('title', 'Ubah Kontak')

@section('content')
<div class="max-w-xl rounded-xl border border-slate-200 bg-white p-6 dark:border-slate-800 dark:bg-slate-900">
    <p class="mb-4 text-sm text-slate-500">Klien: <span class="font-medium text-slate-800 dark:text-slate-200">{{ $client->name }}</span></p>
    <form method="POST" action="{{ route('clients.contacts.update', [$client, $contact]) }}" class="space-y-4">
        @csrf
        @method('PUT')
        @include('clients.contacts._form', ['contact' => $contact])
        <div class="flex gap-2 pt-2">
            <button class="rounded-lg bg-brand-600 px-4 py-2 text-sm font-semibold text-white hover:bg-brand-700">Simpan</button>
            <a href="{{ route('clients.show', $client) }}" class="rounded-lg border border-slate-300 px-4 py-2 text-sm font-medium hover:bg-slate-50 dark:border-slate-700 dark:hover:bg-slate-800">Batal</a>
        </div>
    </form>
</div>
@endsection
