@extends('layouts.app')

@section('title', 'Ubah Paket Recurring')

@section('content')
<div class="max-w-4xl rounded-xl border border-slate-200 bg-white p-6 dark:border-slate-800 dark:bg-slate-900">
    <form method="POST" action="{{ route('recurring-plans.update', $plan) }}" class="space-y-4">
        @csrf
        @method('PUT')
        @include('recurring-plans._form', ['plan' => $plan, 'products' => $products])

        <p class="text-xs text-slate-500">
            Perubahan item berlaku untuk periode berikutnya. Invoice yang sudah terbit tidak ikut berubah
            (dokumen keuangan bersifat final).
        </p>

        <div class="flex gap-2 pt-2">
            <button class="rounded-lg bg-brand-600 px-4 py-2 text-sm font-semibold text-white hover:bg-brand-700">Simpan perubahan</button>
            <a href="{{ route('recurring-plans.show', $plan) }}" class="rounded-lg border border-slate-300 px-4 py-2 text-sm hover:bg-slate-50 dark:border-slate-700 dark:hover:bg-slate-800">Batal</a>
        </div>
    </form>
</div>
@endsection