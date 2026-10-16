@extends('layouts.app')

@section('title', 'Ubah Quotation ' . $quotation->number)

@section('content')
<div class="max-w-4xl rounded-xl border border-slate-200 bg-white p-6 dark:border-slate-800 dark:bg-slate-900">
    <div class="mb-4 rounded-lg border border-slate-200 bg-slate-50 px-4 py-3 text-sm dark:border-slate-700 dark:bg-slate-800">
        <span class="text-slate-500 dark:text-slate-400">Nomor quotation:</span>
        <span class="font-bold">{{ $quotation->number }}</span>
    </div>

    <form method="POST" action="{{ route('quotations.update', $quotation) }}" class="space-y-4">
        @csrf
        @method('PUT')
        @include('quotations._form', ['quotation' => $quotation])
        <div class="flex gap-2 pt-2">
            <button type="submit" name="save_action" value="draft"
                    class="rounded-lg bg-brand-600 px-4 py-2 text-sm font-semibold text-white hover:bg-brand-700">Simpan perubahan</button>
            <a href="{{ route('quotations.show', $quotation) }}" class="rounded-lg border border-slate-300 px-4 py-2 text-sm hover:bg-slate-50 dark:border-slate-700 dark:hover:bg-slate-800">Batal</a>
        </div>
    </form>
</div>
@endsection