@php
    $inputClass = 'w-full rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm text-slate-900 placeholder:text-slate-400 focus:border-brand-500 focus:outline-none focus:ring-2 focus:ring-brand-100 dark:border-slate-700 dark:bg-slate-800 dark:text-slate-100 dark:focus:border-brand-400 dark:focus:ring-brand-900';
    $products = $products ?? collect();
    $plan = $plan ?? null;

    $clientOptions = ['' => '— Pilih klien —'] + collect($clients)->mapWithKeys(fn ($c) => [$c->id => $c->name])->all();
    $serviceOptions = ['' => '— Tanpa layanan —'] + collect($services)->mapWithKeys(fn ($s) => [$s->id => $s->name])->all();
    $cycleOptions = collect($cycles)->mapWithKeys(fn ($c) => [$c->value => $c->label() . ' (' . $c->shortLabel() . ')'])->all();
    $productOptions = ['' => '— Produk —'] + collect($products)->mapWithKeys(fn ($p) => [$p->id => $p->name])->all();

    $cycleValue = old('cycle', $plan?->cycle?->value ?? 'monthly');

    /** @var \App\Domains\Invoicing\Models\RecurringPlan|null $plan */
    $existingItems = $plan
        ? $plan->items->map(fn ($item) => [
            'description' => $item->description,
            'quantity' => $item->quantity,
            'unit_price' => $item->unit_price,
        ])->all()
        : [];

    $itemRows = old('items');
    if (! is_array($itemRows) || $itemRows === []) {
        $itemRows = $existingItems !== [] ? $existingItems : [['description' => '', 'quantity' => 1, 'unit_price' => 0]];
    }
@endphp

<div class="grid gap-4 sm:grid-cols-2">
    <x-input name="client_id" label="Klien" type="select" :required="true" :options="$clientOptions"
             :value="(string) old('client_id', $plan?->client_id)" inputId="plan-client" />
    <x-input name="service_id" label="Layanan terkait (opsional)" type="select" :options="$serviceOptions"
             :value="(string) old('service_id', $plan?->service_id)" inputId="plan-service"
             hint="Hanya layanan milik klien terpilih yang bisa dipilih." />
    <div class="sm:col-span-2">
        <x-input name="title" label="Judul paket" :required="true" :value="$plan?->title"
                 placeholder="mis. Hosting + Domain Bulanan" />
    </div>
    <x-input name="cycle" label="Siklus tagihan" type="select" :required="true" :options="$cycleOptions" :value="$cycleValue" />
    <x-input name="next_invoice_date" label="Tagihan berikutnya" type="date" :required="true"
             :value="old('next_invoice_date', $plan?->next_invoice_date?->format('Y-m-d') ?? today()->format('Y-m-d'))"
             hint="Awal periode penagihan berikutnya. Invoice terbit otomatis saat tanggal ini tercapai." />
    <x-input name="due_days" label="Jatuh tempo (hari)" type="number" min="1" max="90" step="1"
             :value="old('due_days', $plan?->due_days ?? 14)"
             hint="Jatuh tempo = tanggal invoice terbit + jumlah hari ini." />

    <div class="space-y-2 pt-6">
        <label class="flex items-center gap-2 text-sm">
            <input name="active" type="checkbox" value="1" class="rounded border-slate-300 text-brand-600 focus:ring-brand-500"
                   @checked(old('active', $plan?->active ?? true))>
            Paket aktif
        </label>
        <label class="flex items-center gap-2 text-sm">
            <input name="auto_send" type="checkbox" value="1" class="rounded border-slate-300 text-brand-600 focus:ring-brand-500"
                   @checked(old('auto_send', $plan?->auto_send ?? false))>
            Langsung kirim invoice (tanpa review draf)
        </label>
        <label class="flex items-center gap-2 text-sm">
            <input name="copy_service" type="checkbox" value="1" class="rounded border-slate-300 text-brand-600 focus:ring-brand-500" id="copy-service"
                   @checked(old('copy_service'))>
            Isi item otomatis dari layanan terpilih
        </label>
    </div>

    <div class="sm:col-span-2">
        <label class="mb-1 block text-sm font-medium">Item <span class="text-red-600">*</span></label>
        <x-card :padded="false">
            <x-table>
                <thead><tr>
                    <th>Produk</th>
                    <th>Deskripsi</th>
                    <th>Qty</th>
                    <th>Harga satuan</th>
                    <th class="text-right">Nominal</th>
                    <th></th>
                </tr></thead>
                <tbody id="items-body">
                    @foreach ($itemRows as $row)
                        <tr class="item-row">
                            <td>
                                <x-input name="item_product_{{ $loop->index }}" type="select" :options="$productOptions"
                                         data-role="product" class="mb-0" title="Isi deskripsi & harga otomatis" />
                            </td>
                            <td>
                                <x-input name="items[{{ $loop->index }}][description]" required
                                         :value="$row['description'] ?? ''"
                                         placeholder="Deskripsi item…" class="mb-0" data-role="description" />
                            </td>
                            <td>
                                <x-input name="items[{{ $loop->index }}][quantity]" type="number" min="1" step="1"
                                         :value="$row['quantity'] ?? 1" class="mb-0" data-role="quantity" />
                            </td>
                            <td>
                                <x-input name="items[{{ $loop->index }}][unit_price]" type="number" min="0" step="1"
                                         :value="$row['unit_price'] ?? 0" class="mb-0" data-role="unit_price" />
                            </td>
                            <td class="text-right font-medium tabular-nums" data-role="amount">—</td>
                            <td class="text-center">
                                <button type="button" class="text-red-500 hover:text-red-700" data-role="remove" title="Hapus baris">
                                    <i data-lucide="trash-2" class="h-4 w-4"></i>
                                </button>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
                <tfoot>
                    <tr class="border-t border-slate-100 dark:border-slate-800">
                        <td colspan="4" class="px-3 py-2 text-right font-semibold">Total per periode</td>
                        <td class="px-3 py-2 text-right font-bold tabular-nums" id="items-total">—</td>
                        <td></td>
                    </tr>
                </tfoot>
            </x-table>
        </x-card>
        <div class="mt-2">
            <x-btn type="button" variant="outline" icon="plus" id="add-item">Tambah item</x-btn>
        </div>
        @error('items')<p class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror
        @error('items.*.description')<p class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror
        @error('items.*.quantity')<p class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror
        @error('items.*.unit_price')<p class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror
    </div>

    <div class="sm:col-span-2">
        <x-input name="notes" label="Catatan (ikut ke invoice)" type="textarea" rows="2" :value="$plan?->notes" />
    </div>
</div>

<template id="item-row-template">
    <tr class="item-row">
        <td>
            <select data-role="product" title="Isi deskripsi & harga otomatis" class="{{ $inputClass }}">
                <option value="">— Produk —</option>
                @foreach ($products as $p)
                    <option value="{{ $p->id }}" data-name="{{ $p->name }}" data-price="{{ $p->sales_price }}">{{ $p->name }}</option>
                @endforeach
            </select>
        </td>
        <td>
            <input name="__NAME__[description]" required placeholder="Deskripsi item…" class="{{ $inputClass }}" data-role="description">
        </td>
        <td>
            <input name="__NAME__[quantity]" type="number" min="1" step="1" value="1" class="{{ $inputClass }}" data-role="quantity">
        </td>
        <td>
            <input name="__NAME__[unit_price]" type="number" min="0" step="1" value="0" class="{{ $inputClass }}" data-role="unit_price">
        </td>
        <td class="px-3 py-2 text-right font-medium tabular-nums" data-role="amount">—</td>
        <td class="px-3 py-2 text-center">
            <button type="button" class="text-red-500 hover:text-red-700" data-role="remove" title="Hapus baris">
                <i data-lucide="trash-2" class="h-4 w-4"></i>
            </button>
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
