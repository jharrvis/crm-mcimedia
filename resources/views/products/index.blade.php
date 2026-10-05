@extends('layouts.app')

@section('title', 'Produk')

@section('content')
<x-page-header title="Produk" subtitle="Katalog produk dan layanan yang bisa dimasukkan ke invoice.">
    <x-btn :href="route('products.create')" icon="plus">Tambah produk</x-btn>
    <x-btn :href="route('product-categories.index')" variant="outline" icon="tags">Kelola kategori</x-btn>
</x-page-header>

<x-card>
    <form method="GET" action="{{ route('products.index') }}" class="mb-4 grid gap-2 sm:grid-cols-2 lg:grid-cols-4">
        <input name="q" value="{{ request('q') }}" placeholder="Cari nama / SKU / varian…"
               class="w-full rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm placeholder:text-slate-400 focus:border-brand-500 focus:outline-none focus:ring-2 focus:ring-brand-100 dark:border-slate-700 dark:bg-slate-800 dark:text-slate-100 dark:focus:border-brand-400 dark:focus:ring-brand-900">
        <select name="status"
                class="w-full rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm focus:border-brand-500 focus:outline-none focus:ring-2 focus:ring-brand-100 dark:border-slate-700 dark:bg-slate-800 dark:text-slate-100">
            <option value="all" @selected($statusFilter === 'all')>Semua status</option>
            <option value="active" @selected($statusFilter === 'active')>Aktif</option>
            <option value="inactive" @selected($statusFilter === 'inactive')>Nonaktif</option>
        </select>
        <select name="category_id"
                class="w-full rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm focus:border-brand-500 focus:outline-none focus:ring-2 focus:ring-brand-100 dark:border-slate-700 dark:bg-slate-800 dark:text-slate-100">
            <option value="">Semua kategori</option>
            @foreach ($categories ?? [] as $category)
                <option value="{{ $category->id }}" @selected((string) ($categoryFilter ?? '') === (string) $category->id)>{{ $category->name }}</option>
            @endforeach
            <option value="0" @selected((string) ($categoryFilter ?? '') === '0')>Tanpa kategori</option>
        </select>
        <x-btn type="submit" variant="dark" icon="search">Filter</x-btn>
    </form>

    <x-table>
        <thead><tr>
            <th>SKU</th>
            <th>Nama</th>
            <th class="text-right">Harga</th>
            <th>Status</th>
            <th>Kategori</th>
            <th class="text-right">Aksi</th>
        </tr></thead>
        <tbody>
            @forelse ($products as $product)
                <tr>
                    <td class="font-mono text-xs text-slate-400">{{ $product->sku ?? '—' }}</td>
                    <td>
                        <span class="font-semibold">{{ $product->name }}</span>
                        @if ($product->description)<p class="text-xs text-slate-400">{{ $product->description }}</p>@endif
                    </td>
                    <td class="text-right tabular-nums">{{ rupiah($product->sales_price) }}</td>
                    <td>
                        <x-badge :variant="$product->is_active ? 'success' : 'slate'" :dot="true">
                            {{ $product->is_active ? 'Aktif' : 'Nonaktif' }}
                        </x-badge>
                    </td>
                    <td>
                        @if ($product->category)<x-badge variant="slate">{{ $product->category->name }}</x-badge>@else<span class="text-slate-400">—</span>@endif
                        @if ($product->variants->count() > 0)<x-badge variant="info" class="ml-1">{{ $product->variants->count() }} varian</x-badge>@endif
                    </td>
                    <td class="whitespace-nowrap text-right">
                        <a href="{{ route('products.edit', $product) }}" class="text-sm font-semibold text-brand-600 hover:underline">Ubah</a>
                        <span class="mx-1 text-slate-300">|</span>
                        <form method="POST" action="{{ route('products.toggle', $product) }}" class="inline">
                            @csrf
                            @method('PATCH')
                            <button type="submit" class="text-sm font-semibold text-brand-600 hover:underline">{{ $product->is_active ? 'Nonaktifkan' : 'Aktifkan' }}</button>
                        </form>
                        <span class="mx-1 text-slate-300">|</span>
                        <form method="POST" action="{{ route('products.destroy', $product) }}" class="inline" onsubmit="return confirm('Hapus produk ini? Invoice lama tidak terpengaruh.')">
                            @csrf
                            @method('DELETE')
                            <button type="submit" class="text-sm font-semibold text-rose-600 hover:underline">Hapus</button>
                        </form>
                    </td>
                </tr>
            @empty
                <tr><td colspan="6">
                    <x-empty-state title="Belum ada produk" icon="package-open">
                        Tambahkan produk pertama agar mudah dimasukkan ke invoice.
                        <x-slot:action>
                            <x-btn :href="route('products.create')" icon="plus">Tambah produk</x-btn>
                        </x-slot:action>
                    </x-empty-state>
                </td></tr>
            @endforelse
        </tbody>
    </x-table>

    @if ($products->hasPages())
        <div class="mt-4">{{ $products->links() }}</div>
    @endif
</x-card>
@endsection