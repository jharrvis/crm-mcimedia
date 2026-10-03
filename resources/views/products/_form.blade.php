@php
    $inputClass = 'w-full rounded-lg border border-slate-300 px-3 py-2 text-sm dark:border-slate-700 dark:bg-slate-800';
    // Saat edit: varian tersimpan. Saat create: kosong. old() mengganti saat validasi gagal.
    $oldVariants = old('variants');
    $existingVariants = $product?->variants?->map(fn ($v) => [
        'id' => $v->id, 'name' => $v->name, 'sku' => $v->sku,
        'sales_price' => $v->sales_price, 'is_active' => $v->is_active, 'sort_order' => $v->sort_order,
    ])?->toArray() ?? [];
    $variantRows = is_array($oldVariants) && ! empty($oldVariants) ? $oldVariants : $existingVariants;
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
        <label class="mb-1 block text-sm font-medium">Kategori</label>
        <select name="category_id" class="{{ $inputClass }}">
            <option value="">— Tanpa kategori —</option>
            @foreach ($categories ?? [] as $category)
                <option value="{{ $category->id }}" @selected((int) old('category_id', $product?->category_id) === $category->id)>{{ $category->name }}</option>
            @endforeach
        </select>
        @error('category_id')<p class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror
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

    {{-- Penanda agar controller tahu form ini membawa data varian; sync selalu dilakukan. --}}
    <input type="hidden" name="variants_sync" value="1">

    <div class="sm:col-span-2 rounded-lg border border-slate-200 p-4 dark:border-slate-700">
        <div class="mb-2 flex items-center justify-between">
            <h3 class="text-sm font-semibold">Varian produk <span class="text-xs font-normal text-slate-500">(opsional)</span></h3>
            <button type="button" data-role="add-variant" class="rounded-lg border border-slate-300 px-3 py-1 text-xs font-semibold hover:bg-slate-50 dark:border-slate-600 dark:hover:bg-slate-800">+ Tambah varian</button>
        </div>
        @error('variants')<p class="mb-2 text-xs text-red-600">{{ $message }}</p>@enderror
        <div data-role="variants-body" class="space-y-2">
            @foreach ($variantRows as $i => $variant)
                <div class="variant-row grid grid-cols-12 items-start gap-2" data-index="{{ $i }}">
                    <input type="hidden" name="variants[{{ $i }}][id]" value="{{ $variant['id'] ?? '' }}">
                    <div class="col-span-4">
                        <input name="variants[{{ $i }}][name]" value="{{ $variant['name'] ?? '' }}" placeholder="Nama varian (mis. 1GB)" class="{{ $inputClass }}">
                        @error("variants.$i.name")<p class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror
                    </div>
                    <div class="col-span-3">
                        <input name="variants[{{ $i }}][sku]" value="{{ $variant['sku'] ?? '' }}" placeholder="SKU (opsional)" class="{{ $inputClass }} font-mono">
                        @error("variants.$i.sku")<p class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror
                    </div>
                    <div class="col-span-3">
                        <input name="variants[{{ $i }}][sales_price]" type="number" min="0" step="1" value="{{ $variant['sales_price'] ?? 0 }}" placeholder="Harga (IDR)" class="{{ $inputClass }}">
                        @error("variants.$i.sales_price")<p class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror
                    </div>
                    <div class="col-span-2 flex items-center gap-2 pt-2">
                        <input type="hidden" name="variants[{{ $i }}][is_active]" value="0">
                        <label class="flex items-center gap-1 text-xs" title="Varian aktif">
                            <input name="variants[{{ $i }}][is_active]" type="checkbox" value="1" class="rounded" @checked(old("variants.$i.is_active", $variant['is_active'] ?? true))>
                            Aktif
                        </label>
                        <button type="button" data-role="remove-variant" title="Hapus varian" class="text-red-500 hover:text-red-700">✕</button>
                    </div>
                </div>
            @endforeach
        </div>
        @if (empty($variantRows))
            <p class="text-xs text-slate-500" data-role="variants-empty">Belum ada varian. Tambahkan bila produk ini punya opsi/harga berbeda.</p>
        @endif
    </div>
</div>

<template data-role="variant-template">
    <div class="variant-row grid grid-cols-12 items-start gap-2" data-index="__INDEX__">
        <input type="hidden" name="variants[__INDEX__][id]" value="">
        <div class="col-span-4">
            <input name="variants[__INDEX__][name]" placeholder="Nama varian (mis. 1GB)" class="{{ $inputClass }}">
        </div>
        <div class="col-span-3">
            <input name="variants[__INDEX__][sku]" placeholder="SKU (opsional)" class="{{ $inputClass }} font-mono">
        </div>
        <div class="col-span-3">
            <input name="variants[__INDEX__][sales_price]" type="number" min="0" step="1" value="0" placeholder="Harga (IDR)" class="{{ $inputClass }}">
        </div>
        <div class="col-span-2 flex items-center gap-2 pt-2">
            <input type="hidden" name="variants[__INDEX__][is_active]" value="0">
            <label class="flex items-center gap-1 text-xs" title="Varian aktif">
                <input name="variants[__INDEX__][is_active]" type="checkbox" value="1" class="rounded" checked>
                Aktif
            </label>
            <button type="button" data-role="remove-variant" title="Hapus varian" class="text-red-500 hover:text-red-700">✕</button>
        </div>
    </div>
</template>

<script>
    (() => {
        const body = document.querySelector('[data-role="variants-body"]');
        const empty = document.querySelector('[data-role="variants-empty"]');
        const template = document.querySelector('template[data-role="variant-template"]');
        if (!body || !template) return;

        const nextIndex = () => body.querySelectorAll('.variant-row').length
            ? Math.max(...[...body.querySelectorAll('.variant-row')].map(r => Number(r.dataset.index))) + 1
            : 0;

        document.querySelector('[data-role="add-variant"]')?.addEventListener('click', () => {
            const i = nextIndex();
            body.insertAdjacentHTML('beforeend', template.innerHTML.replace(/__INDEX__/g, i));
            empty?.classList.add('hidden');
        });

        body.addEventListener('click', (e) => {
            if (e.target.closest('[data-role="remove-variant"]')) {
                e.target.closest('.variant-row')?.remove();
                if (body.querySelectorAll('.variant-row').length === 0) empty?.classList.remove('hidden');
            }
        });
    })();
</script>