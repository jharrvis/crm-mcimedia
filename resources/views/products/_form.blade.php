@php
    $inputClass = 'w-full rounded-lg border border-slate-300 px-3 py-2 text-sm dark:border-slate-700 dark:bg-slate-800';
@endphp

<div class="grid gap-4 sm:grid-cols-2">
    <div class="sm:col-span-2">
        <label class="mb-1 block text-sm font-medium">Nama produk <span class="text-red-600">*</span></label>
        <input name="name" required value="{{ old('name', $product?->name) }}" placeholder="mis. Hosting 1GB (SG)" class="{{ $inputClass }}">
        @error('name')<p class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror
    </div>
    <div>
        <label class="mb-1 block text-sm font-medium">SKU</label>
        <input name="sku" value="{{ old('sku', $product?->sku) }}" placeholder="mis. SKU0012" class="{{ $inputClass }} font-mono">
        @error('sku')<p class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror
    </div>
    <div>
        <label class="mb-1 block text-sm font-medium">Harga jual (IDR)</label>
        <input name="sales_price" type="number" min="0" step="1" value="{{ old('sales_price', $product?->sales_price ?? 0) }}" class="{{ $inputClass }}">
        @error('sales_price')<p class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror
    </div>
    <div>
        <label class="mb-1 block text-sm font-medium">Urutan tampil</label>
        <input name="sort_order" type="number" min="0" step="1" value="{{ old('sort_order', $product?->sort_order ?? 0) }}" class="{{ $inputClass }}">
        @error('sort_order')<p class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror
    </div>
    <div class="sm:col-span-2">
        <label class="mb-1 block text-sm font-medium">Deskripsi</label>
        <textarea name="description" rows="3" class="{{ $inputClass }}">{{ old('description', $product?->description) }}</textarea>
        @error('description')<p class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror
    </div>
    <div class="sm:col-span-2">
        {{-- Hidden 0 memastikan checkbox yang dilepas tetap terkirim sebagai nonaktif. --}}
        <input type="hidden" name="is_active" value="0">
        <label class="flex items-center gap-2 text-sm">
            <input name="is_active" type="checkbox" value="1" class="rounded" @checked(old('is_active', $product?->is_active ?? true))>
            Produk aktif (tampil di picker invoice)
        </label>
    </div>
</div>
