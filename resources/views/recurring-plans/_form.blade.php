@php
    $inputClass = 'w-full rounded-lg border border-slate-300 px-3 py-2 text-sm dark:border-slate-700 dark:bg-slate-800';
    $products = $products ?? collect();

    $cycleValue = old('cycle', $plan?->cycle?->value ?? 'monthly');

    /** @var \App\Domains\Invoicing\Models\RecurringPlan|null $plan */
    $existingItems = isset($plan) && $plan
        ? $plan->items->map(fn ($item) => [
            'description' => $item->description,
            'quantity' => $item->quantity,
            'unit_price' => $item->unit_price,
        ])->all()
        : [];

    // old() hanya dipakai bila form pernah disubmit — supaya admin yang
    // sengaja mengosongkan semua item tetap melihat form kosongnya.
    $itemRows = old('items');
    if (! is_array($itemRows) || $itemRows === []) {
        $itemRows = $existingItems !== [] ? $existingItems : [['description' => '', 'quantity' => 1, 'unit_price' => 0]];
    }
@endphp

<div class="grid gap-4 sm:grid-cols-2">
    <div>
        <x-client-select
            :clients="$clients"
            :selected="old('client_id', $plan?->client_id)"
            id="plan-client"
            label="Klien"
            required />
    </div>

    <div>
        <label class="mb-1 block text-sm font-medium">Layanan terkait (opsional)</label>
        <select name="service_id" id="plan-service" class="{{ $inputClass }}">
            <option value="">— Tanpa layanan —</option>
            @foreach ($services as $s)
                <option value="{{ $s->id }}"
                        data-client="{{ $s->client_id }}"
                        data-name="{{ $s->name }}"
                        data-price="{{ $s->price }}"
                        @selected(old('service_id', $plan?->service_id) == $s->id)>{{ $s->name }}</option>
            @endforeach
        </select>
        <p class="mt-1 text-xs text-slate-500">Hanya layanan milik klien terpilih yang bisa dipilih.</p>
        @error('service_id')<p class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror
    </div>

    <div class="sm:col-span-2">
        <label class="mb-1 block text-sm font-medium">Judul paket <span class="text-red-600">*</span></label>
        <input name="title" required value="{{ old('title', $plan?->title) }}"
               placeholder="mis. Hosting + Domain Bulanan" class="{{ $inputClass }}">
        @error('title')<p class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror
    </div>

    <div>
        <label class="mb-1 block text-sm font-medium">Siklus tagihan <span class="text-red-600">*</span></label>
        <select name="cycle" required class="{{ $inputClass }}">
            @foreach ($cycles as $c)
                <option value="{{ $c->value }}" @selected($cycleValue === $c->value)>
                    {{ $c->label() }} ({{ $c->shortLabel() }})
                </option>
            @endforeach
        </select>
        @error('cycle')<p class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror
    </div>

    <div>
        <label class="mb-1 block text-sm font-medium">Tagihan berikutnya <span class="text-red-600">*</span></label>
        <input name="next_invoice_date" type="date" required class="{{ $inputClass }}"
               value="{{ old('next_invoice_date', $plan?->next_invoice_date?->format('Y-m-d') ?? today()->format('Y-m-d')) }}">
        <p class="mt-1 text-xs text-slate-500">Awal periode penagihan berikutnya. Invoice terbit otomatis saat tanggal ini tercapai.</p>
        @error('next_invoice_date')<p class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror
    </div>

    <div>
        <label class="mb-1 block text-sm font-medium">Jatuh tempo (hari)</label>
        <input name="due_days" type="number" min="1" max="90" step="1" class="{{ $inputClass }}"
               value="{{ old('due_days', $plan?->due_days ?? 14) }}">
        <p class="mt-1 text-xs text-slate-500">Jatuh tempo = tanggal invoice terbit + jumlah hari ini.</p>
        @error('due_days')<p class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror
    </div>

    <div class="space-y-2 pt-6">
        <label class="flex items-center gap-2 text-sm">
            <input name="active" type="checkbox" value="1" class="rounded"
                   @checked(old('active', $plan?->active ?? true))>
            Paket aktif
        </label>
        <label class="flex items-center gap-2 text-sm">
            <input name="auto_send" type="checkbox" value="1" class="rounded"
                   @checked(old('auto_send', $plan?->auto_send ?? false))>
            Langsung kirim invoice (tanpa review draf)
        </label>
        <label class="flex items-center gap-2 text-sm">
            <input name="copy_service" type="checkbox" value="1" class="rounded" id="copy-service"
                   @checked(old('copy_service'))>
            Isi item otomatis dari layanan terpilih
        </label>
    </div>

    <div class="sm:col-span-2">
        <label class="mb-1 block text-sm font-medium">Item <span class="text-red-600">*</span></label>
        <div class="overflow-x-auto rounded-lg border border-slate-300 dark:border-slate-700">
            <table class="w-full text-sm">
                <thead class="bg-slate-50 text-left dark:bg-slate-800">
                    <tr>
                        <th class="px-3 py-2">Produk</th>
                        <th class="px-3 py-2">Deskripsi</th>
                        <th class="px-3 py-2">Qty</th>
                        <th class="px-3 py-2">Harga satuan</th>
                        <th class="px-3 py-2 text-right">Nominal</th>
                        <th class="px-3 py-2"></th>
                    </tr>
                </thead>
                <tbody id="items-body">
                    @foreach ($itemRows as $row)
                        <tr class="item-row border-t border-slate-100 dark:border-slate-800">
                            <td class="px-3 py-2">
                                <select data-role="product" class="{{ $inputClass }}" title="Isi deskripsi & harga otomatis">
                                    <option value="">— Produk —</option>
                                    @foreach ($products as $p)
                                        <option value="{{ $p->id }}" data-name="{{ $p->name }}" data-price="{{ $p->sales_price }}">{{ $p->name }}</option>
                                    @endforeach
                                </select>
                            </td>
                            <td class="px-3 py-2">
                                <input name="items[{{ $loop->index }}][description]" required
                                       value="{{ $row['description'] ?? '' }}"
                                       placeholder="Deskripsi item…" class="{{ $inputClass }}" data-role="description">
                            </td>
                            <td class="px-3 py-2">
                                <input name="items[{{ $loop->index }}][quantity]" type="number" min="1" step="1"
                                       value="{{ $row['quantity'] ?? 1 }}" class="{{ $inputClass }}" data-role="quantity">
                            </td>
                            <td class="px-3 py-2">
                                <input name="items[{{ $loop->index }}][unit_price]" type="number" min="0" step="1"
                                       value="{{ $row['unit_price'] ?? 0 }}" class="{{ $inputClass }}" data-role="unit_price">
                            </td>
                            <td class="px-3 py-2 text-right font-medium tabular-nums" data-role="amount">—</td>
                            <td class="px-3 py-2 text-center">
                                <button type="button" class="text-red-500 hover:text-red-700" data-role="remove" title="Hapus baris">✕</button>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
                <tfoot class="bg-slate-50 dark:bg-slate-800">
                    <tr>
                        <td colspan="4" class="px-3 py-2 text-right font-semibold">Total per periode</td>
                        <td class="px-3 py-2 text-right font-bold tabular-nums" id="items-total">—</td>
                        <td></td>
                    </tr>
                </tfoot>
            </table>
        </div>
        <button type="button" id="add-item"
                class="mt-2 rounded-lg border border-slate-300 px-3 py-1.5 text-sm hover:bg-slate-50 dark:border-slate-700 dark:hover:bg-slate-800">
            + Tambah item
        </button>
        @error('items')<p class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror
        @error('items.*.description')<p class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror
        @error('items.*.quantity')<p class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror
        @error('items.*.unit_price')<p class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror
    </div>

    <div class="sm:col-span-2">
        <label class="mb-1 block text-sm font-medium">Catatan (ikut ke invoice)</label>
        <textarea name="notes" rows="2" class="{{ $inputClass }}">{{ old('notes', $plan?->notes) }}</textarea>
        @error('notes')<p class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror
    </div>
</div>

<template id="item-row-template">
    <tr class="item-row border-t border-slate-100 dark:border-slate-800">
        <td class="px-3 py-2">
            <select data-role="product" class="{{ $inputClass }}" title="Isi deskripsi & harga otomatis">
                <option value="">— Produk —</option>
                @foreach ($products as $p)
                    <option value="{{ $p->id }}" data-name="{{ $p->name }}" data-price="{{ $p->sales_price }}">{{ $p->name }}</option>
                @endforeach
            </select>
        </td>
        <td class="px-3 py-2">
            <input name="__NAME__[description]" required placeholder="Deskripsi item…" class="{{ $inputClass }}" data-role="description">
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
        const clientSelect = document.getElementById('plan-client');
        const serviceSelect = document.getElementById('plan-service');
        const copyService = document.getElementById('copy-service');

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

        document.getElementById('add-item').addEventListener('click', () => {
            addItemRow(null);
            reindex();
            body.querySelector('tr.item-row:last-child [data-role=description]').focus();
        });

        body.addEventListener('click', (e) => {
            const btn = e.target.closest('[data-role=remove]');
            if (btn && body.querySelectorAll('tr.item-row').length > 1) {
                btn.closest('tr.item-row').remove();
                reindex();
            }
        });

        body.addEventListener('input', recalc);

        body.addEventListener('change', (e) => {
            const select = e.target.closest('[data-role=product]');
            if (! select || ! select.value) return;

            const row = select.closest('tr.item-row');
            const opt = select.selectedOptions[0];
            row.querySelector('[data-role=description]').value = opt.dataset.name;
            row.querySelector('[data-role=unit_price]').value = opt.dataset.price;
            recalc();
        });

        // Hanya layanan milik klien terpilih yang boleh dipilih; opsi lain
        // disembunyikan (server juga memvalidasinya lewat RecurringPlanRequest).
        function filterServices() {
            const clientId = clientSelect.value;
            Array.from(serviceSelect.options).forEach((opt) => {
                if (! opt.value) return;
                const foreign = Boolean(clientId) && opt.dataset.client !== clientId;
                opt.hidden = foreign;
                opt.disabled = foreign;
                if (foreign && opt.selected) {
                    serviceSelect.value = '';
                }
            });
        }

        clientSelect.addEventListener('change', filterServices);
        filterServices();
        recalc();

        // Isi baris item pertama dari layanan terpilih (hanya saat create —
        // saat edit baris existing tidak boleh diam-dimpang tertimpa).
        if (copyService && serviceSelect) {
            const fill = () => {
                const opt = serviceSelect.selectedOptions[0];
                if (! opt || ! opt.value) return;

                const first = body.querySelector('tr.item-row');
                const filled = Array.from(body.querySelectorAll('tr.item-row'))
                    .some((row) => row.querySelector('[data-role=description]').value.trim() !== '');
                if (filled && !confirm('Ganti baris item dengan satu baris dari layanan terpilih?')) return;

                body.querySelectorAll('tr.item-row').forEach((row) => row.remove());
                addItemRow({ description: opt.dataset.name, quantity: 1, unit_price: opt.dataset.price });
                reindex();
            };

            serviceSelect.addEventListener('change', fill);
        }
    })();
</script>