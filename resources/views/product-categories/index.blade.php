@extends('layouts.app')

@section('title', 'Kategori Produk')

@section('content')
<div class="mb-4 flex flex-wrap items-center gap-2">
    <a href="{{ route('product-categories.create') }}" class="rounded-lg bg-indigo-600 px-4 py-2 text-sm font-semibold text-white hover:bg-indigo-700">Tambah kategori</a>
    <a href="{{ route('products.index') }}" class="rounded-lg border border-slate-300 px-4 py-2 text-sm hover:bg-slate-50 dark:border-slate-700 dark:hover:bg-slate-800">← Kembali ke produk</a>
</div>

<div class="overflow-x-auto rounded-xl border border-slate-200 bg-white dark:border-slate-800 dark:bg-slate-900">
    <table class="w-full text-sm">
        <thead>
            <tr class="text-left text-xs uppercase text-slate-500">
                <th class="px-4 py-3">#</th>
                <th class="px-4 py-3">Nama</th>
                <th class="px-4 py-3">Deskripsi</th>
                <th class="px-4 py-3 text-right">Produk</th>
                <th class="px-4 py-3 text-right">Aksi</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($categories as $category)
                <tr class="border-t border-slate-100 dark:border-slate-800">
                    <td class="px-4 py-3 tabular-nums text-slate-500">{{ $category->sort_order }}</td>
                    <td class="px-4 py-3">
                        <span class="font-medium">{{ $category->name }}</span>
                        <p class="text-xs text-slate-400">{{ $category->slug }}</p>
                    </td>
                    <td class="px-4 py-3 text-slate-500">{{ $category->description ?? '—' }}</td>
                    <td class="px-4 py-3 text-right">
                        <a href="{{ route('products.index', ['category_id' => $category->id]) }}" class="text-indigo-600 hover:underline">{{ $category->products_count }} produk</a>
                    </td>
                    <td class="px-4 py-3 text-right whitespace-nowrap">
                        <a href="{{ route('product-categories.edit', $category) }}" class="text-indigo-600 hover:underline">Ubah</a>
                        <span class="mx-1 text-slate-300">|</span>
                        <form method="POST" action="{{ route('product-categories.destroy', $category) }}" class="inline" onsubmit="return confirm('Hapus kategori ini? Produknya tetap tersimpan tanpa kategori.')">
                            @csrf
                            @method('DELETE')
                            <button class="text-red-600 hover:underline">Hapus</button>
                        </form>
                    </td>
                </tr>
            @empty
                <tr><td colspan="5" class="px-4 py-8 text-center text-slate-500">Belum ada kategori.</td></tr>
            @endforelse
        </tbody>
    </table>
</div>
@endsection
