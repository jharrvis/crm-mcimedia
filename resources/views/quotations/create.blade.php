@extends('layouts.app')

@section('title', 'Buat Quotation')

@section('content')
<div class="max-w-4xl rounded-xl border border-slate-200 bg-white p-6 dark:border-slate-800 dark:bg-slate-900">
    {{-- Nomor quotation berikutnya ditampilkan saat buat baru (mirror UX-5 invoice). --}}
    <div class="mb-4 rounded-lg border border-brand-200 bg-brand-50 px-4 py-3 text-sm dark:border-brand-800 dark:bg-brand-950">
        <span class="text-slate-500 dark:text-slate-400">Nomor quotation:</span>
        <span class="font-bold text-brand-700 dark:text-brand-300">{{ $previewNumber }}</span>
        <span class="ml-1 text-xs text-slate-400">(diberikan otomatis saat disimpan)</span>
    </div>

    <form method="POST" action="{{ route('quotations.store') }}" class="space-y-4">
        @csrf
        @include('quotations._form', ['quotation' => null])
        {{-- Dua tombol submit native (name="save_action"): tanpa JS pun tetap jalan. --}}
        <div class="flex gap-2 pt-2">
            <button type="submit" name="save_action" value="draft"
                    class="rounded-lg bg-brand-600 px-4 py-2 text-sm font-semibold text-white hover:bg-brand-700">Simpan draf</button>
            <button type="submit" name="save_action" value="confirm"
                    class="rounded-lg border border-brand-300 px-4 py-2 text-sm font-semibold text-brand-600 hover:bg-brand-50 dark:border-brand-700 dark:hover:bg-brand-950">Simpan &amp; tandai terkirim</button>
            <a href="{{ route('quotations.index') }}" class="rounded-lg border border-slate-300 px-4 py-2 text-sm hover:bg-slate-50 dark:border-slate-700 dark:hover:bg-slate-800">Batal</a>
        </div>
    </form>
</div>
@endsection