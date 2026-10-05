@extends('layouts.app')

@section('title', 'Ubah Invoice ' . $invoice->number)

@section('content')
<div class="max-w-4xl rounded-xl border border-slate-200 bg-white p-6 dark:border-slate-800 dark:bg-slate-900">
    <form method="POST" action="{{ route('invoices.update', $invoice) }}" class="space-y-4">
        @csrf
        @method('PUT')
        @include('invoices._form', ['invoice' => $invoice])
        <div class="flex gap-2 pt-2">
            <div id="invoice-save-split" class="relative inline-flex">
                <button type="submit" name="save_action" value="draft"
                        class="rounded-l-lg bg-brand-600 px-4 py-2 text-sm font-semibold text-white hover:bg-brand-700">Simpan perubahan</button>
                <button type="button" data-save-toggle aria-expanded="false" aria-haspopup="menu" aria-label="Opsi simpan lainnya"
                        class="rounded-r-lg border-l border-brand-500 bg-brand-600 px-2.5 py-2 text-sm font-semibold text-white hover:bg-brand-700">&#9662;</button>
                <div data-save-menu hidden role="menu" class="absolute left-0 top-full z-40 mt-1 min-w-60 overflow-hidden rounded-xl border border-slate-200 bg-white shadow-xl dark:border-slate-700 dark:bg-slate-800">
                    <button type="submit" name="save_action" value="send" role="menuitemradio"
                            class="block w-full px-4 py-2.5 text-left text-sm hover:bg-slate-50 dark:hover:bg-slate-700">Simpan &amp; lanjut ke pengiriman</button>
                    <button type="submit" name="save_action" value="confirm" role="menuitemradio"
                            class="block w-full px-4 py-2.5 text-left text-sm hover:bg-slate-50 dark:hover:bg-slate-700">Simpan &amp; tandai terkirim</button>
                    <button type="submit" name="save_action" value="draft" role="menuitemradio"
                            class="block w-full px-4 py-2.5 text-left text-sm hover:bg-slate-50 dark:hover:bg-slate-700">Simpan draf</button>
                </div>
            </div>
            <a href="{{ route('invoices.show', $invoice) }}" class="rounded-lg border border-slate-300 px-4 py-2 text-sm hover:bg-slate-50 dark:border-slate-700 dark:hover:bg-slate-800">Batal</a>
        </div>
    </form>
</div>
@endsection
