@extends('layouts.app')

@section('title', 'Kategori Produk')

@section('content')
<x-page-header title="Kategori Produk" back="{{ route('products.index') }}" backLabel="Kembali ke produk" icon="tags"
               subtitle="Kelompokkan produk agar invoice lebih rapi.">
    <x-btn :href="route('product-categories.create')" icon="plus">Tambah kategori</x-btn>
</x-page-header>

<x-card>
    <x-table>
        <thead><tr>
            <th>#</th>
            <th>Nama</th>
            <th>Deskripsi</th>
            <th class="text-right">Produk</th>
            <th class="text-right">Aksi</th>
        </tr></thead>
        <tbody>
            @forelse ($categories as $category)
                <tr>
                    <td class="tabular-nums text-slate-400">{{ $category->sort_order }}</td>
                    <td>
                        <span class="font-semibold">{{ $category->name }}</span>
                        <p class="text-xs text-slate-400">{{ $category->slug }}</p>
                    </td>
                    <td class="text-slate-400">{{ $category->description ?? '—' }}</td>
                    <td class="text-right">
                        <a href="{{ route('products.index', ['category_id' => $category->id]) }}" class="text-sm font-semibold text-brand-600 hover:underline">{{ $category->products_count }} produk</a>
                    </td>
                    <td class="whitespace-nowrap text-right">
                        <a href="{{ route('product-categories.edit', $category) }}" class="text-sm font-semibold text-brand-600 hover:underline">Ubah</a>
                        <span class="mx-1 text-slate-300">|</span>
                        <form method="POST" action="{{ route('product-categories.destroy', $category) }}" class="inline" onsubmit="return confirm('Hapus kategori ini? Produknya tetap tersimpan tanpa kategori.')">
                            @csrf
                            @method('DELETE')
                            <button type="submit" class="text-sm font-semibold text-rose-600 hover:underline">Hapus</button>
                        </form>
                    </td>
                </tr>
            @empty
                <tr><td colspan="5">
                    <x-empty-state title="Belum ada kategori" icon="tags">
                        Buat kategori agar produk lebih rapi dan mudah dicari saat membuat invoice.
                        <x-slot:action>
                            <x-btn :href="route('product-categories.create')" icon="plus">Tambah kategori</x-btn>
                        </x-slot:action>
                    </x-empty-state>
                </td></tr>
            @endforelse
        </tbody>
    </x-table>

    @if ($categories->hasPages())
        <div class="mt-4">{{ $categories->links() }}</div>
    @endif
</x-card>
@endsection