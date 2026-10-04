@php
    use App\Domains\Invoicing\Models\Invoice;

    $inputClass = 'w-full rounded-lg border border-slate-300 px-3 py-2 text-sm dark:border-slate-700 dark:bg-slate-800';

    // Produk aktif untuk picker baris item (F2-3) — dirender server sebagai
    // bootstrap JSON (termasuk varian aktifnya), query lebih lanjut via AJAX
    // /products/picker/search. Boleh kosong bila belum ada katalog.
    $products = $products ?? collect();
    // Kategori untuk modal tambah cepat; disuplai controller create/edit.
    $categories = $categories ?? collect();
    // Tombol "Tambahkan ... ke stok" hanya bila user punya izin manage modul
    // products — sama dengan resolveAction() middleware permission (POST → manage).
    // Server tetap menolak via middleware; ini sekadar menyembunyikan opsi.
    $canCreateProducts = auth()->user()?->hasPermission('products', 'manage') ?? false;

    /** @var Invoice|null $invoice */
    $existingItems = isset($invoice) && $invoice
        ? $invoice->items->map(fn ($item) => [
            'description' => $item->description,
            'quantity' => $item->quantity,
            'unit_price' => $item->unit_price,
        ])->all()
        : [];

    // Layanan yang sudah tercakup invoice (F4-8: relasi many-to-many).
    // old() dipakai hanya bila form pernah disubmit — supaya admin yang
    // sengaja mencentang kosong semua layanan tidak dikembalikan ke pilihan lama.
    $oldServiceIds = old('service_ids');
    $selectedServices = $oldServiceIds !== null
        ? collect($oldServiceIds)->filter(fn ($id) => filled($id))->map(fn ($id) => (int) $id)->all()
        : ($invoice?->services?->pluck('id')->map(fn ($id) => (int) $id)->all() ?? []);

    // Baris item: pakai old() saat validasi gagal, data invoice saat edit, satu baris kosong saat create.
    $itemRows = old('items');
    if (! is_array($itemRows) || $itemRows === []) {
        $itemRows = $existingItems !== [] ? $existingItems : [['description' => '', 'quantity' => 1, 'unit_price' => 0]];
    }
@endphp

<div class="grid gap-4 sm:grid-cols-2" data-can-create-products="{{ $canCreateProducts ? '1' : '0' }}">
    <div>
        <label class="mb-1 block text-sm font-medium">Klien <span class="text-red-600">*</span></label>
        <select name="client_id" id="invoice-client" required class="{{ $inputClass }}">
            <option value="">— Pilih klien —</option>
            @foreach ($clients as $c)
                <option value="{{ $c->id }}" @selected(old('client_id', $invoice?->client_id) == $c->id)>{{ $c->name }}</option>
            @endforeach
        </select>
        @error('client_id')<p class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror
    </div>
    <div class="sm:col-span-2">
        <label class="mb-1 block text-sm font-medium">Layanan terkait (boleh lebih dari satu)</label>
        <div id="invoice-services"
             class="max-h-56 space-y-1 overflow-y-auto rounded-lg border border-slate-300 p-2 dark:border-slate-700">
            @forelse ($services as $s)
                <label class="flex items-center gap-2 rounded px-2 py-1 text-sm hover:bg-slate-50 dark:hover:bg-slate-800">
                    <input type="checkbox" name="service_ids[]" value="{{ $s->id }}"
                           data-client="{{ $s->client_id }}"
                           data-name="{{ $s->name }}"
                           data-price="{{ $s->price }}"
                           @checked(in_array((int) $s->id, $selectedServices, true))
                           class="service-check rounded border-slate-300 text-indigo-600">
                    <span class="service-label">{{ $s->name }}</span>
                    <span class="ml-auto text-xs tabular-nums text-slate-500">{{ rupiah($s->price) }}</span>
                </label>
            @empty
                <p class="px-2 py-1 text-sm text-slate-500">Belum ada layanan. Invoice tetap bisa dibuat tanpa layanan.</p>
            @endforelse
        </div>
        <p class="mt-1 text-xs text-slate-500">Centang semua layanan yang dicakup dalam satu invoice (mis. pembayaran bulanan beberapa website sekaligus).</p>
        @error('service_ids')<p class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror
        @error('service_ids.*')<p class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror
    </div>
    <div class="sm:col-span-2">
        <label class="mb-1 block text-sm font-medium">Judul</label>
        <input name="title" value="{{ old('title', $invoice?->title) }}" placeholder="mis. Perpanjangan Hosting contoh.com" class="{{ $inputClass }}">
        @error('title')<p class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror
    </div>
    <div>
        <label class="mb-1 block text-sm font-medium">Tanggal terbit <span class="text-red-600">*</span></label>
        <input name="issue_date" type="date" required value="{{ old('issue_date', $invoice?->issue_date?->format('Y-m-d') ?? now()->toDateString()) }}" class="{{ $inputClass }}">
        @error('issue_date')<p class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror
    </div>
    <div>
        @include('invoices._due-date-picker', [
            'name' => 'due_date',
            'value' => old('due_date', $invoice?->due_date?->format('Y-m-d') ?? now()->addDays(14)->toDateString()),
            'label' => 'Jatuh tempo',
            'required' => true,
            'inputClass' => $inputClass,
        ])
    </div>
    <div class="sm:col-span-2">
        <label class="mb-1 block text-sm font-medium">Catatan</label>
        <textarea name="notes" rows="2" class="{{ $inputClass }}">{{ old('notes', $invoice?->notes) }}</textarea>
        @error('notes')<p class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror
    </div>
</div>

{{-- Bootstrap picker produk: JSON tersimpan di <script type="application/json">
     agar < dan > dalam nama produk tidak membobol markup. Hex-escape < agar
     `</script>` di data tidak menutup tag lebih awal. --}}
<script type="application/json" id="picker-bootstrap">@json($products, JSON_HEX_TAG)</script>
<script type="application/json" id="picker-categories">@json($categories, JSON_HEX_TAG)</script>

<!-- Item invoice (dinamis) -->
<div class="mt-6">
    <div class="mb-2 flex flex-wrap items-center justify-between gap-2">
        <h2 class="text-sm font-semibold">Item invoice <span class="text-red-600">*</span></h2>
        <div class="flex gap-2">
            <button type="button" id="fill-from-services"
                    class="rounded-lg border border-emerald-300 px-3 py-1.5 text-xs font-semibold text-emerald-700 hover:bg-emerald-50 dark:border-emerald-800 dark:text-emerald-400 dark:hover:bg-emerald-950">
                Isi dari layanan dipilih
            </button>
            <button type="button" id="add-item" class="rounded-lg border border-indigo-300 px-3 py-1.5 text-xs font-semibold text-indigo-600 hover:bg-indigo-50 dark:border-indigo-700 dark:hover:bg-indigo-950">+ Tambah baris</button>
        </div>
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
                    <th class="w-56 px-3 py-2">Produk <span class="font-normal normal-case text-[10px]">— ketik buku & produk</span></th>
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
                    <td colspan="4" class="px-3 py-2 text-right text-xs font-semibold uppercase text-slate-500">Total (server menghitung ulang)</td>
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
        const clientSelect = document.getElementById('invoice-client');
        const serviceBox = document.getElementById('invoice-services');
        const serviceChecks = () => Array.from(serviceBox?.querySelectorAll('.service-check') ?? []);

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
            addItemRow(null);
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

        // Sembunyikan layanan yang bukan milik klien terpilih dan lepas centangnya,
        // supaya tidak ada service_ids milik klien lain yang ikut terkirim.
        function filterServices() {
            const clientId = clientSelect.value;
            serviceChecks().forEach((check) => {
                const row = check.closest('label');
                const foreign = Boolean(clientId) && check.dataset.client !== clientId;
                row.classList.toggle('hidden', foreign);
                if (foreign) {
                    check.checked = false;
                    check.disabled = true;
                } else {
                    check.disabled = false;
                }
            });
        }

        clientSelect.addEventListener('change', filterServices);

        // F4-8: buat satu baris item per layanan yang dicentang, memakai harga
        // layanan. Tombol ini bersifat " isi ulang dari layanan": SELURUH baris
        // item diganti karena baris hasil server (edit) tidak bisa dibedakan dari
        // baris manual. Karena itu, klik ulang selalu idempoten — tidak pernah
        // menghasilkan baris ganda.
        function addItemRow(values) {
            const row = template.content.firstElementChild.cloneNode(true);
            row.querySelectorAll('input[name]').forEach((input) => {
                input.name = input.name.replace('__NAME__', `items[${body.querySelectorAll('tr.item-row').length}]`);
            });
            if (values) {
                row.querySelector('[data-role=description]').value = values.description;
                row.querySelector('[data-role=quantity]').value = values.quantity;
                row.querySelector('[data-role=unit_price]').value = values.unit_price;
            }
            body.appendChild(row);
        }

        document.getElementById('fill-from-services').addEventListener('click', () => {
            const chosen = serviceChecks().filter((check) => check.checked);

            if (chosen.length === 0) {
                alert('Centang minimal satu layanan terlebih dahulu.');
                return;
            }

            // Jangan diam-diam menghapus item yang sudah diketik manual.
            const hasFilledRows = Array.from(body.querySelectorAll('tr.item-row'))
                .some((row) => row.querySelector('[data-role=description]').value.trim() !== '');
            if (hasFilledRows && !confirm('Ganti seluruh baris item dengan satu baris per layanan yang dipilih?')) {
                return;
            }

            body.querySelectorAll('tr.item-row').forEach((row) => row.remove());

            chosen.forEach((check) => {
                addItemRow({
                    description: check.dataset.name,
                    quantity: 1,
                    unit_price: check.dataset.price,
                });
            });

            reindex();
        });

        filterServices();
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
            <p class="mt-1 text-xs text-slate-500">Produk baru langsung tersedia di picker invoice.</p>
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
            <button type="button" data-role="quick-confirm" class="rounded-lg bg-indigo-600 px-4 py-2 text-sm font-semibold text-white hover:bg-indigo-700">Simpan Produk</button>
        </div>
    </div>
</dialog>
