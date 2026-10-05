@extends('layouts.app')

@section('title', 'Tambah Produk')

@section('content')
<div class="max-w-3xl rounded-xl border border-slate-200 bg-white p-6 dark:border-slate-800 dark:bg-slate-900">
    <form method="POST" action="{{ route('products.store') }}" class="space-y-4">
        @csrf
        @include('products._form', ['product' => null, 'categories' => $categories ?? collect()])
        <div class="flex gap-2 pt-2">
            <button class="rounded-lg bg-brand-600 px-4 py-2 text-sm font-semibold text-white hover:bg-brand-700">Simpan</button>
            <a href="{{ route('products.index') }}" class="rounded-lg border border-slate-300 px-4 py-2 text-sm hover:bg-slate-50 dark:border-slate-700 dark:hover:bg-slate-800">Batal</a>
        </div>
    </form>
</div>
@endsection
