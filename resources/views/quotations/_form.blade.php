@php
    use App\Domains\Quotation\Models\Quotation;

    $inputClass = 'w-full rounded-lg border border-slate-300 px-3 py-2 text-sm dark:border-slate-700 dark:bg-slate-800';

    // Produk aktif untuk picker baris item (mirror invoice UX-2) — dirender
    // server sebagai bootstrap JSON, query lebih lanjut via AJAX
    // /products/picker/search. Boleh kosong bila belum ada katalog.
    $products = $products ?? collect();
    // Kategori untuk modal tambah cepat; disuplai controller create/edit.
    $categories = $categories ?? collect();
    // Tombol "Tambahkan ... ke stok" hanya bila user punya izin manage modul
    // products — sama dengan resolveAction() middleware permission (POST → manage).
    $canCreateProducts = auth()->user()?->hasPermission('products', 'manage') ?? false;

    /** @var Quotation|null $quotation */
    $existingItems = isset($quotation) && $quotation
        ? $quotation->items->map(fn ($item) => [
            'description' => $item->description,
            'quantity' => $item->quantity,
            'unit_price' => $item->unit_price,
        ])->all()
        : [];

    // Baris item: pakai old() saat validasi gagal, data quotation saat edit, satu baris kosong saat create.
    $itemRows = old('items');
    if (! is_array($itemRows) || $itemRows === []) {
        $itemRows = $existingItems !== [] ? $existingItems : [['description' => '', 'quantity' => 1, 'unit_price' => 0]];
    }
@endphp

<div class="grid gap-4 sm:grid-cols-2" data-can-create-products="{{ $canCreateProducts ? '1' : '0' }}">
    <div>
        <label class="mb-1 block text-sm font-medium">Klien <span class="text-red-600">*</span></label>
        <select name="client_id" id="quotation-client" required class="{{ $inputClass }}">
            <option value="">— Pilih klien —</option>
            @foreach ($clients as $c)
                <option value="{{ $c->id }}" @selected(old('client_id', $quotation?->client_id) == $c->id)>{{ $c->name }}</option>
            @endforeach
        </select>
        @error('client_id')<p class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror
    </div>
    <div class="sm:col-span-2">
        <label class="mb-1 block text-sm font-medium">Judul</label>
        <input name="title" value="{{ old('title', $quotation?->title) }}" placeholder="mis. Penawaran Pengembangan Website" class="{{ $inputClass }}">
        @error('title')<p class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror
    </div>
    <div>
        <label class="mb-1 block text-sm font-medium">Tanggal terbit <span class="text-red-600">*</span></label>
        <input name="issue_date" type="date" required value="{{ old('issue_date', $quotation?->issue_date?->format('Y-m-d') ?? now()->toDateString()) }}" class="{{ $inputClass }}">
        @error('issue_date')<p class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror
    </div>
    <div>
        <label class="mb-1 block text-sm font-medium">Masa berlaku <span class="text-red-600">*</span></label>
        <input name="valid_until" type="date" required value="{{ old('valid_until', $quotation?->valid_until?->format('Y-m-d') ?? now()->addDays(30)->toDateString()) }}" class="{{ $inputClass }}">
        <p class="mt-1 text-xs text-slate-500">Default 30 hari sejak tanggal terbit.</p>
        @error('valid_until')<p class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror
    </div>
    <div>
        <label class="mb-1 block text-sm font-medium">Diskon (IDR)</label>
        <input name="discount" type="number" min="0" step="1" value="{{ old('discount', $quotation?->discount ?? 0) }}" class="{{ $inputClass }}">
        <p class="mt-1 text-xs text-slate-500">Potongan dalam rupiah. Total tidak bisa negatif.</p>
        @error('discount')<p class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror
    </div>
    <div class="sm:col-span-2">
        <label class="mb-1 block text-sm font-medium">Catatan</label>
        <textarea name="notes" rows="2" class="{{ $inputClass }}">{{ old('notes', $quotation?->notes) }}</textarea>
        @error('notes')<p class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror
    </div>
    <div class="sm:col-span-2">
        <label class="mb-1 block text-sm font-medium">Syarat &amp; ketentuan</label>
        <textarea name="terms" rows="3" class="{{ $inputClass }}" placeholder="mis. DP 50% sebelum pengerjaan dimulai…">{{ old('terms', $quotation?->terms) }}</textarea>
        @error('terms')<p class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror
    </div>
</div>

{{-- Bootstrap picker produk: JSON tersimpan di <script type="application/json">
     agar < dan > dalam nama produk tidak membobol markup. Hex-escape < agar
     `</script>` di data tidak menutup tag lebih awal. --}}
<script type="application/json" id="picker-bootstrap">@json($products, JSON_HEX_TAG)</script>
<script type="application/json" id="picker-categories">@json($categories, JSON_HEX_TAG)</script>

<!-- Item quotation (dinamis) -->
<div class="mt-6">
    <div class="mb-2 flex flex-wrap items-center justify-between gap-2">
        <h2 class="text-sm font-semibold">Item penawaran <span class="text-red-600">*</span></h2>
        <button type="button" id="add-item" class="rounded-lg border border-brand-300 px-3 py-1.5 text-xs font-semibold text-brand-600 hover:bg-brand-50 dark:border-brand-700 dark:hover:bg-brand-950">+ Tambah baris</button>
    </div>
    @error('items')<p class="mb-2 text-xs text-red-600">{{ $message }}</p>@enderror
    @php
        $itemErrors = collect($errors->messages())
            ->filter(fn ($messages, $key) => str_starts_with($key, 'items.'))
            ->flatten()
            ->unique()
            ->all();
    @endphp
    @foreach ($itemErrors as $itemError)
        <p class="mb-2 text-xs text-red-600">{{ $itemError }}</p>
    @endforeach

    <div class="overflow-x-auto rounded-lg border border-slate-200 dark:border-slate-700">
        <table class="w-full text-sm" id="items-table">
            <thead>
                <tr class="bg-slate-50 text-left text-xs uppercase text-slate-500 dark:bg-slate-800">
                    <th class="w-56 px-3 py-2">Produk <span class="font-normal normal-case text-[10px]">— cari dari katalog</span></th>
                    <th class="px-3 py-2">Deskripsi</th>
                    <th class="w-24 px-3 py-2">Qty</th>
                    <th class="w-40 px-3 py-2">Harga satuan (IDR)</th>
                    <th class="w-36 px-3 py-2 text-right">Jumlah</th>
                    <th class="w-12 px-3 py-2"></th>
                </tr>
            </thead>
            <tbody id="items-body">
                @foreach ($itemRows as $row)
                    <tr class="item-row border-t border-slate-100 dark:border-slate-800">
                        <td class="px-3 py-2">
                            <div data-role="product-search-box" class="relative">
                                <input type="text" data-role="product-search" autocomplete="off" spellcheck="false" placeholder="Cari nama atau SKU…" class="{{ $inputClass }} pr-8" title="Ketik nama/SKU produk, pilih dari saran, lalu pilih varian bila ada — deskripsi & harga satuan auto-terisi">
                                <span class="pointer-events-none absolute right-2 top-1/2 -translate-y-1/2 text-slate-400" aria-hidden="true">&#9660;</span>
                            </div>
                            <div data-role="variant-select-box" class="mt-1.5" hidden></div>
                        </td>
                        <td class="px-3 py-2">
                            <input name="items[{{ $loop->index }}][description]" value="{{ $row['description'] ?? '' }}" placeholder="Deskripsi item…" class="{{ $inputClass }}" data-role="description">
                        </td>
                        <td class="px-3 py-2">
                            <input name="items[{{ $loop->index }}][quantity]" type="number" min="1" step="1" value="{{ $row['quantity'] ?? 1 }}" class="{{ $inputClass }}" data-role="quantity">
                        </td>
                        <td class="px-3 py-2">
                            <input name="items[{{ $loop->index }}][unit_price]" type="number" min="0" step="1" value="{{ $row['unit_price'] ?? 0 }}" class="{{ $inputClass }}" data-role="unit_price">
                        </td>
                        <td class="px-3 py-2 text-right font-medium tabular-nums" data-role="amount">—</td>
                        <td class="px-3 py-2 text-center">
                            <button type="button" class="text-red-500 hover:text-red-700" data-role="remove" title="Hapus baris">✕</button>
                        </td>
                    </tr>
                @endforeach
            </tbody>
            <tfoot>
                <tr class="border-t border-slate-200 bg-slate-50 dark:border-slate-700 dark:bg-slate-800">
                    <td colspan="4" class="px-3 py-2 text-right text-xs font-semibold uppercase text-slate-500">Subtotal (server menghitung ulang, diskon dipotong terpisah)</td>
                    <td class="px-3 py-2 text-right font-bold tabular-nums" id="items-total">—</td>
                    <td></td>
                </tr>
            </tfoot>
        </table>
    </div>
</div>

<template id="item-row-template">
    <tr class="item-row border-t border-slate-100 dark:border-slate-800">
        <td class="px-3 py-2">
            <div data-role="product-search-box" class="relative">
                <input type="text" data-role="product-search" autocomplete="off" spellcheck="false" placeholder="Cari nama atau SKU…" class="{{ $inputClass }} pr-8" title="Ketik nama/SKU produk, pilih dari saran, lalu pilih varian bila ada — deskripsi & harga satuan auto-terisi">
                <span class="pointer-events-none absolute right-2 top-1/2 -translate-y-1/2 text-slate-400" aria-hidden="true">&#9660;</span>
            </div>
            <div data-role="variant-select-box" class="mt-1.5" hidden></div>
        </td>
        <td class="px-3 py-2">
            <input name="__NAME__[description]" placeholder="Deskripsi item…" class="{{ $inputClass }}" data-role="description">
        </td>
        <td class="px-3 py-2">
            <input name="__NAME__[quantity]" type="number" min="1" step="1" value="1" class="{{ $inputClass }}" data-role="quantity">
        </td>
        <td class="px-3 py-2">
            <input name="__NAME__[unit_price]" type="number" min="0" step="1" value="0" class="{{ $inputClass }}" data-role="unit_price">
        </td>
        <td class="px-3 py-2 text-right font-medium tabular-nums" data-role="amount">—</td>
        <td class="px-3 py-2 text-center">
            <button type="button" class="text-red-500 hover:text-red-700" data-role="remove" title="Hapus baris">✕</button>
        </td>
    </tr>
</template>

<script>
    (() => {
        const body = document.getElementById('items-body');
        const template = document.getElementById('item-row-template');
        const totalEl = document.getElementById('items-total');

        const fmt = (n) => 'Rp ' + n.toLocaleString('id-ID');

        function reindex() {
            body.querySelectorAll('tr.item-row').forEach((row, i) => {
                row.querySelectorAll('input[name]').forEach((input) => {
                    input.name = input.name.replace(/items\[\d+\]/, `items[${i}]`);
                });
            });
            recalc();
        }

        function recalc() {
            let total = 0;
            body.querySelectorAll('tr.item-row').forEach((row) => {
                const qty = parseInt(row.querySelector('[data-role=quantity]').value, 10) || 0;
                const price = parseInt(row.querySelector('[data-role=unit_price]').value, 10) || 0;
                const amount = qty * price;
                row.querySelector('[data-role=amount]').textContent = amount > 0 ? fmt(amount) : '—';
                total += amount;
            });
            totalEl.textContent = total > 0 ? fmt(total) : '—';
        }

        document.getElementById('add-item').addEventListener('click', () => {
            const row = template.content.firstElementChild.cloneNode(true);
            row.querySelectorAll('input[name]').forEach((input) => {
                input.name = input.name.replace('__NAME__', `items[${body.querySelectorAll('tr.item-row').length}]`);
            });
            body.appendChild(row);
            reindex();
            body.querySelector('tr.item-row:last-child [data-role=description]').focus();
        });

        body.addEventListener('click', (e) => {
            const btn = e.target.closest('[data-role=remove]');
            if (btn) {
                btn.closest('tr.item-row').remove();
                reindex();
            }
        });

        body.addEventListener('input', recalc);

        // Picker produk (live search + varian) ditangani di resources/js/product-picker.js.
        // Admin tetap bisa mengubah deskripsi & harga manual setelahnya.
        // product_id TIDAK dikirim ke server (snapshot nama + harga saja).

        recalc();
    })();
</script>

{{-- Dialog tambah cepat produk (AJAX ke products/picker/quick-create).
     <dialog> native: Esc/overlay ditangani browser. --}}
<dialog id="picker-quick-create" data-quick-create-form
        class="w-[92vw] max-w-md rounded-xl border border-slate-200 bg-white p-0 backdrop:bg-slate-900/50 dark:border-slate-700 dark:bg-slate-900">
    <div data-quick-layer class="p-5 space-y-4">
        <div>
            <h3 class="text-base font-semibold">Tambah Produk ke Stok</h3>
            <p class="mt-1 text-xs text-slate-500">Produk baru langsung tersedia di picker quotation.</p>
        </div>
        <div>
            <label class="mb-1 block text-sm font-medium">Nama Produk <span class="text-red-600">*</span></label>
            <input type="text" data-quick-name maxlength="255" class="{{ $inputClass }}" placeholder="mis. Hosting 5GB (SG)">
        </div>
        <div>
            <label class="mb-1 block text-sm font-medium">Harga Jual (IDR)</label>
            <input type="number" data-quick-price min="0" step="1" value="0" class="{{ $inputClass }}">
        </div>
        <div>
            <label class="mb-1 block text-sm font-medium">Kategori</label>
            <select data-quick-category class="{{ $inputClass }}">
                <option value="">— Tanpa kategori —</option>
                @foreach ($categories as $cat)
                    <option value="{{ $cat->id }}">{{ $cat->name }}</option>
                @endforeach
            </select>
        </div>
        <p data-quick-error class="hidden text-xs text-red-600"></p>
        <div class="flex justify-end gap-2 pt-1">
            <button type="button" data-role="quick-cancel" class="rounded-lg border border-slate-300 px-4 py-2 text-sm hover:bg-slate-50 dark:border-slate-700 dark:hover:bg-slate-800">Batal</button>
            <button type="button" data-role="quick-confirm" class="rounded-lg bg-brand-600 px-4 py-2 text-sm font-semibold text-white hover:bg-brand-700">Simpan Produk</button>
        </div>
    </div>
</dialog>