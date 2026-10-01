@php
    use App\Domains\Invoicing\Models\Invoice;

    $inputClass = 'w-full rounded-lg border border-slate-300 px-3 py-2 text-sm dark:border-slate-700 dark:bg-slate-800';

    /** @var Invoice|null $invoice */
    $existingItems = isset($invoice) && $invoice
        ? $invoice->items->map(fn ($item) => [
            'description' => $item->description,
            'quantity' => $item->quantity,
            'unit_price' => $item->unit_price,
        ])->all()
        : [];

    // Baris item: pakai old() saat validasi gagal, data invoice saat edit, satu baris kosong saat create.
    $itemRows = old('items');
    if (! is_array($itemRows) || $itemRows === []) {
        $itemRows = $existingItems !== [] ? $existingItems : [['description' => '', 'quantity' => 1, 'unit_price' => 0]];
    }
@endphp

<div class="grid gap-4 sm:grid-cols-2">
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
    <div>
        <label class="mb-1 block text-sm font-medium">Layanan terkait (opsional)</label>
        <select name="service_id" id="invoice-service" class="{{ $inputClass }}">
            <option value="">— Tanpa layanan —</option>
            @foreach ($services as $s)
                <option value="{{ $s->id }}" data-client="{{ $s->client_id }}" @selected(old('service_id', $invoice?->service_id) == $s->id)>{{ $s->name }}</option>
            @endforeach
        </select>
        @error('service_id')<p class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror
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
        <label class="mb-1 block text-sm font-medium">Jatuh tempo <span class="text-red-600">*</span></label>
        <input name="due_date" type="date" required value="{{ old('due_date', $invoice?->due_date?->format('Y-m-d') ?? now()->addDays(14)->toDateString()) }}" class="{{ $inputClass }}">
        @error('due_date')<p class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror
    </div>
    <div class="sm:col-span-2">
        <label class="mb-1 block text-sm font-medium">Catatan</label>
        <textarea name="notes" rows="2" class="{{ $inputClass }}">{{ old('notes', $invoice?->notes) }}</textarea>
        @error('notes')<p class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror
    </div>
</div>

<!-- Item invoice (dinamis) -->
<div class="mt-6">
    <div class="mb-2 flex items-center justify-between">
        <h2 class="text-sm font-semibold">Item invoice <span class="text-red-600">*</span></h2>
        <button type="button" id="add-item" class="rounded-lg border border-indigo-300 px-3 py-1.5 text-xs font-semibold text-indigo-600 hover:bg-indigo-50 dark:border-indigo-700 dark:hover:bg-indigo-950">+ Tambah baris</button>
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
                    <td colspan="3" class="px-3 py-2 text-right text-xs font-semibold uppercase text-slate-500">Total (server menghitung ulang)</td>
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
        const serviceSelect = document.getElementById('invoice-service');

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
            row.querySelector('[data-role=description]').focus();
        });

        body.addEventListener('click', (e) => {
            const btn = e.target.closest('[data-role=remove]');
            if (btn) {
                btn.closest('tr.item-row').remove();
                reindex();
            }
        });

        body.addEventListener('input', recalc);

        // Sembunyikan opsi layanan yang bukan milik klien terpilih.
        function filterServices() {
            const clientId = clientSelect.value;
            Array.from(serviceSelect.options).forEach((opt) => {
                if (! opt.value) return;
                opt.hidden = Boolean(clientId) && opt.dataset.client !== clientId;
            });
            if (serviceSelect.selectedOptions[0]?.hidden) {
                serviceSelect.value = '';
            }
        }

        clientSelect.addEventListener('change', filterServices);
        filterServices();
        recalc();
    })();
</script>
