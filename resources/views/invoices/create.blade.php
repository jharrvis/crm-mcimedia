@extends('layouts.app')

@section('title', 'Buat Invoice')

@section('content')
<div class="max-w-4xl rounded-xl border border-slate-200 bg-white p-6 dark:border-slate-800 dark:bg-slate-900">
    <form method="POST" action="{{ route('invoices.store') }}" class="space-y-4">
        @csrf
        @include('invoices._form', ['invoice' => null])
        <div class="flex gap-2 pt-2">
            <button class="rounded-lg bg-indigo-600 px-4 py-2 text-sm font-semibold text-white hover:bg-indigo-700">Simpan invoice</button>
            <a href="{{ route('invoices.index') }}" class="rounded-lg border border-slate-300 px-4 py-2 text-sm hover:bg-slate-50 dark:border-slate-700 dark:hover:bg-slate-800">Batal</a>
        </div>
    </form>
</div>
@endsection
