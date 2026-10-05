@extends('layouts.app')

@section('title', 'Buat Paket Recurring')

@section('content')
<div class="max-w-4xl rounded-xl border border-slate-200 bg-white p-6 dark:border-slate-800 dark:bg-slate-900">
    <div class="mb-4">
        <h2 class="text-lg font-bold">Paket invoice recurring</h2>
        <p class="text-sm text-slate-500">
            Invoice terbit otomatis sesuai siklus (bulanan / 3 / 6 / 12 bulan) tanpa perlu dibuat manual tiap periode.
        </p>
    </div>

    <form method="POST" action="{{ route('recurring-plans.store') }}" class="space-y-4">
        @csrf
        @include('recurring-plans._form', ['plan' => null, 'products' => $products])
        <div class="flex gap-2 pt-2">
            <button class="rounded-lg bg-brand-600 px-4 py-2 text-sm font-semibold text-white hover:bg-brand-700">Simpan paket</button>
            <a href="{{ route('recurring-plans.index') }}" class="rounded-lg border border-slate-300 px-4 py-2 text-sm hover:bg-slate-50 dark:border-slate-700 dark:hover:bg-slate-800">Batal</a>
        </div>
    </form>
</div>
@endsection