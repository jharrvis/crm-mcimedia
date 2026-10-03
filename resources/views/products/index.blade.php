@extends('layouts.app')

@section('title', 'Produk')

@section('content')
<div class="mb-4 flex flex-wrap items-center gap-2">
    <a href="{{ route('products.create') }}" class="rounded-lg bg-indigo-600 px-4 py-2 text-sm font-semibold text-white hover:bg-indigo-700">Tambah produk</a>
    <a href="{{ route('product-categories.index') }}" class="rounded-lg border border-slate-300 px-4 py-2 text-sm hover:bg-slate-50 dark:border-slate-700 dark:hover:bg-slate-800">Kelola kategori</a>
</div>

<form method="GET" action="{{ route('products.index') }}" class="mb-4 grid gap-2 rounded-xl border border-slate-200 bg-white p-4 sm:grid-cols-4 dark:border-slate-800 dark:bg-slate-900">
    <input name="q" value="{{ request('q') }}" placeholder="Cari nama / SKU / varian…" class="rounded-lg border border-slate-300 px-3 py-2 text-sm dark:border-slate-700 dark:bg-slate-800">
    <select name="status" class="rounded-lg border border-slate-300 px-3 py-2 text-sm dark:border-slate-700 dark:bg-slate-800">
        <option value="all" @selected($statusFilter === 'all')>Semua status</option>
        <option value="active" @selected($statusFilter === 'active')>Aktif</option>
        <option value="inactive" @selected($statusFilter === 'inactive')>Nonaktif</option>
    </select>
    <select name="category_id" class="rounded-lg border border-slate-300 px-3 py-2 text-sm dark:border-slate-700 dark:bg-slate-800">
        <option value="">Semua kategori</option>
        @foreach ($categories ?? [] as $category)
            <option value="{{ $category->id }}" @selected((string) ($categoryFilter ?? '') === (string) $category->id)>{{ $category->name }}</option>
        @endforeach
        <option value="0" @selected((string) ($categoryFilter ?? '') === '0')>Tanpa kategori</option>
    </select>
    <button class="rounded-lg bg-slate-800 px-4 py-2 text-sm font-semibold text-white hover:bg-slate-700 dark:bg-slate-700">Filter</button>
</form>

<div class="overflow-x-auto rounded-xl border border-slate-200 bg-white dark:border-slate-800 dark:bg-slate-900">
    <table class="w-full text-sm">
        <thead>
            <tr class="text-left text-xs uppercase text-slate-500">
                <th class="px-4 py-3">SKU</th>
                <th class="px-4 py-3">Nama</th>
                <th class="px-4 py-3 text-right">Harga</th>
                <th class="px-4 py-3">Kategori</th>
                <th class="px-4 py-3">Status</th>
                <th class="px-4 py-3 text-right">Aksi</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($products as $product)
                <tr class="border-t border-slate-100 dark:border-slate-800">
                    <td class="px-4 py-3 font-mono text-xs text-slate-500">{{ $product->sku ?? '—' }}</td>
                    <td class="px-4 py-3">
                        <span class="font-medium">{{ $product->name }}</span>
                        @if ($product->description)<p class="text-xs text-slate-500">{{ $product->description }}</p>@endif
                    </td>
                    <td class="px-4 py-3 text-right tabular-nums">{{ rupiah($product->sales_price) }}</td>
                    <td class="px-4 py-3">
                        <span class="rounded-full px-2 py-0.5 text-xs font-medium {{ $product->is_active ? 'bg-green-100 text-green-700 dark:bg-green-900 dark:text-green-200' : 'bg-slate-200 text-slate-600 dark:bg-slate-700 dark:text-slate-300' }}">{{ $product->is_active ? 'Aktif' : 'Nonaktif' }}</span>
                    </td>
                    <td class="px-4 py-3 text-xs">
                        @if ($product->category)<span class="rounded-full bg-slate-100 px-2 py-0.5 font-medium text-slate-600 dark:bg-slate-800 dark:text-slate-300">{{ $product->category->name }}</span>@else<span class="text-slate-400">—</span>@endif
                        @if ($product->variants->count() > 0)<span class="rounded bg-indigo-100 px-1.5 py-0.5 font-medium text-indigo-600 dark:bg-indigo-900 dark:text-indigo-300">{{ $product->variants->count() }} varian</span>@endif
                    </td>
                    <td class="px-4 py-3 text-right whitespace-nowrap">
                        <a href="{{ route('products.edit', $product) }}" class="text-indigo-600 hover:underline">Ubah</a>
                        <span class="mx-1 text-slate-300">|</span>
                        <form method="POST" action="{{ route('products.toggle', $product) }}" class="inline">
                            @csrf
                            @method('PATCH')
                            <button class="text-indigo-600 hover:underline">{{ $product->is_active ? 'Nonaktifkan' : 'Aktifkan' }}</button>
                        </form>
                        <span class="mx-1 text-slate-300">|</span>
                        <form method="POST" action="{{ route('products.destroy', $product) }}" class="inline" onsubmit="return confirm('Hapus produk ini? Invoice lama tidak terpengaruh.')">
                            @csrf
                            @method('DELETE')
                            <button class="text-red-600 hover:underline">Hapus</button>
                        </form>
                    </td>
                </tr>
            @empty
                <tr><td colspan="6" class="px-4 py-8 text-center text-slate-500">Belum ada produk.</td></tr>
            @endforelse
        </tbody>
    </table>
</div>
<div class="mt-4">{{ $products->links() }}</div>
@endsection
