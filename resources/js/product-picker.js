// Picker produk live di baris item invoice (UX-2).
// Satu input teks per baris → server membalas daftar produk (nama/SKU, varian)
// → baris pertama = "Tambahkan query ke stok" (buka modal) → klik baris pilih
// produk → bila produk punya varian muncul dropdown varian, kalau tidak harga
// satuan terisi langsung dari produk. Harga tetap bisa di-override manual.

const ENDPOINT_SEARCH = '/products/picker/search';
const ENDPOINT_CREATE = '/products/picker/quick-create';

function escapeHtml(value) {
    return String(value).replace(/[&<>"']/g, (char) => ({
        '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;',
    }[char]));
}

function escapeHtmlAttr(value) {
    return escapeHtml(value);
}

function highlightMatch(full, query) {
    const needle = String(query || '').trim();
    if (!needle) return escapeHtml(full);
    const lower = String(full).toLowerCase();
    const pos = lower.indexOf(needle.toLowerCase());
    if (pos < 0) return escapeHtml(full);
    return (
        escapeHtml(full.slice(0, pos)) +
        '<mark class="rounded bg-amber-200 px-0.5 font-semibold dark:bg-amber-800">' +
        escapeHtml(full.slice(pos, pos + needle.length)) +
        '</mark>' +
        escapeHtml(full.slice(pos + needle.length))
    );
}

function productPayloadToRowOptions(product) {
    return {
        sku: product.sku || '',
        name: product.name,
        sales_price: Number(product.sales_price) || 0,
        variants: Array.isArray(product.variants) ? product.variants : [],
    };
}

function applySelectionToRow(row, productRow, variantId) {
    const descEl = row.querySelector('[data-role=description]');
    const priceEl = row.querySelector('[data-role=unit_price]');
    if (!descEl || !priceEl) return;
    if (variantId) {
        const variant = productRow.variants.find((v) => String(v.id) === String(variantId));
        if (variant) {
            descEl.value = productRow.name + (variant.name ? ' — ' + variant.name : '');
            priceEl.value = String(variant.sales_price);
            row.querySelector('[data-variant-id]') && (row.querySelector('[data-variant-id]').value = String(variant.id));
            row.dispatchEvent(new Event('input', { bubbles: true }));
            return;
        }
    }
    descEl.value = productRow.name;
    priceEl.value = String(productRow.sales_price);
    row.dispatchEvent(new Event('input', { bubbles: true }));
}

function ensureVariantSelectForRow(row, productRow, onVariantPick) {
    let box = row.querySelector('[data-role=variant-select-box]');
    if (!box) {
        const td = row.querySelector('td:first-child');
        if (!td) return;
        box = document.createElement('div');
        box.dataset.role = 'variant-select-box';
        box.className = 'mt-1.5';
        td.appendChild(box);
    }
    if (!productRow.variants.length) {
        box.innerHTML = '';
        box.hidden = true;
        return;
    }
    box.hidden = false;
    const options = ['<option value="">— Pilih varian —</option>']
        .concat(productRow.variants.map((v) => {
            const label = `${v.name}${v.sku ? ' · ' + v.sku : ''} — Rp ${Number(v.sales_price).toLocaleString('id-ID')}`;
            return `<option value="${escapeHtmlAttr(v.id)}" data-name="${escapeHtmlAttr(v.name)}" data-price="${escapeHtmlAttr(v.sales_price)}">${escapeHtml(label)}</option>`;
        })).join('');
    box.innerHTML = `<select data-role="variant-select" class="w-full rounded-lg border border-slate-300 px-2 py-1 text-xs dark:border-slate-700 dark:bg-slate-800">${options}</select>`;
    const select = box.querySelector('[data-role=variant-select]');
    // Auto-pilih satu-satunya varian.
    if (productRow.variants.length === 1) {
        select.value = String(productRow.variants[0].id);
        onVariantPick(String(select.value));
    }
    select.addEventListener('change', () => {
        if (select.value) onVariantPick(String(select.value));
    });
    // keyboard: Escape sembunyikan ketika fokusan.
}

function buildDropdown({ query, canCreate, matches }) {
    const root = document.createElement('div');
    root.className = 'absolute left-0 right-0 z-40 mt-1 max-h-72 overflow-auto rounded-xl border border-slate-200 bg-white shadow-xl dark:border-slate-700 dark:bg-slate-800';
    root.setAttribute('role', 'listbox');
    root.dataset.pickerDropdown = '1';
    let html = '';
    if (canCreate && query.trim() !== '') {
        const label = escapeHtml(query.trim());
        html += `<button type="button" data-quick-create="${escapeHtmlAttr(query.trim())}" role="option" class="flex w-full items-center gap-2 border-b border-slate-100 px-3 py-2 text-left text-sm hover:bg-indigo-50 dark:border-slate-800 dark:hover:bg-slate-700"><span class="text-indigo-600">+ Tambahkan “</span><span class="font-semibold text-slate-900 dark:text-white">${label}</span><span class="text-indigo-600">” ke stok</span></button>`;
    }
    if (matches && matches.length) {
        for (const p of matches) {
            const price = 'Rp ' + (Number(p.sales_price) || 0).toLocaleString('id-ID');
            const sku = p.sku ? `<span class="mr-2 font-mono text-xs text-slate-500">${escapeHtml(p.sku)}</span>` : '';
            const nameHtml = highlightMatch(p.name, query);
            const hasVariants = p.variants && p.variants.length ? ` <span class="ml-1 rounded bg-amber-100 px-1.5 py-0.5 text-[10px] text-amber-700 dark:bg-amber-900 dark:text-amber-200">${escapeHtml(String(p.variants.length))} varian</span>` : '';
            html += `<button type="button" data-product-id="${escapeHtmlAttr(String(p.id))}" role="option" class="flex w-full items-center justify-between gap-3 px-3 py-2 text-left text-sm hover:bg-slate-50 dark:hover:bg-slate-700"><span>${sku}<span class="text-slate-800 dark:text-slate-100">${nameHtml}</span>${hasVariants}</span><span class="shrink-0 text-xs tabular-nums text-slate-500">${escapeHtml(price)}</span></button>`;
        }
    } else if (query.trim().length >= 2 && !canCreate) {
        html += '<p class="px-3 py-6 text-center text-sm text-slate-500">Tidak ada produk cocok.</p>';
    }
    // No-result dengan canCreate = quick-create button saja (rendered di atas).
    root.innerHTML = html;
    return root;
}

/** Controller untuk satu baris input picker. */
function attachRowPicker(row, context) {
    const input = row.querySelector('[data-role=product-search]');
    if (!input || input.dataset.pickerBound === '1') return;
    input.dataset.pickerBound = '1';

    let dropdown = null;
    let abort = null;
    let debounce = null;

    function closeDropdown() {
        if (dropdown) { dropdown.remove(); dropdown = null; }
        if (abort) { abort.abort(); abort = null; }
    }

    function openDropdownWith(matches, query) {
        closeDropdown();
        const canCreate = context.canCreate;
        if (!matches.length && !canCreate && query.trim() === '') return;
        dropdown = buildDropdown({ query, canCreate, matches });
        const target = row.querySelector('[data-role=product-search-box]') || input.parentElement;
        target.style.position = target.style.position || 'relative';
        target.appendChild(dropdown);

        dropdown.addEventListener('click', async (e) => {
            const addBtn = e.target.closest('[data-quick-create]');
            if (addBtn) {
                const initial = String(addBtn.dataset.quickCreate || query);
                const result = await context.requestQuickCreate(initial, row);
                if (result) {
                    // result = product baru; pilih langsung & beri pilihan varian bila ada.
                    const mapped = productPayloadToRowOptions(result);
                    // Cache baru jadi — sisipkan ke dropdown cache agar input lain menemukannya.
                    context.addLocalProduct(result);
                    applySelectionToRow(row, mapped, null);
                    if (mapped.variants.length) {
                        ensureVariantSelectForRow(row, mapped, (variantId) => applySelectionToRow(row, mapped, variantId));
                    } else {
                        const box = row.querySelector('[data-role=variant-select-box]');
                        if (box) box.hidden = true;
                    }
                    // Mengingat product terpilih memenuhi kebutuhan test sisi server (description+harga).
                    // Tidak menyimpan product_id ke server (sesuai domain: harga dikunci snapshot).
                }
                closeDropdown();
                input.focus();
                return;
            }
            const pickBtn = e.target.closest('[data-product-id]');
            if (pickBtn) {
                const id = String(pickBtn.dataset.productId);
                const product = matches.find((m) => String(m.id) === id) || context.localProducts.find((m) => String(m.id) === id);
                if (!product) return;
                input.value = product.name;
                input.setAttribute('data-selected-name', product.name);
                const mapped = productPayloadToRowOptions(product);
                if (mapped.variants.length) {
                    ensureVariantSelectForRow(row, mapped, (variantId) => applySelectionToRow(row, mapped, variantId));
                    // default: pakai harga produk sampai varian dipilih.
                    applySelectionToRow(row, mapped, null);
                } else {
                    const box = row.querySelector('[data-role=variant-select-box]');
                    if (box) { box.innerHTML = ''; box.hidden = true; }
                    applySelectionToRow(row, mapped, null);
                }
                closeDropdown();
            }
        });
    }

    async function fetchAndShow(query) {
        if (abort) abort.abort();
        abort = new AbortController();
        const url = ENDPOINT_SEARCH + '?q=' + encodeURIComponent(query);
        try {
            const res = await fetch(url, { signal: abort.signal, headers: { Accept: 'application/json' } });
            if (!res.ok) throw new Error('search failed ' + res.status);
            const data = await res.json();
            const matches = Array.isArray(data.products) ? data.products : [];
            openDropdownWith(matches, query);
        } catch (err) {
            if (err && err.name === 'AbortError') return;
            // Fallback: tampilkan quick-create bila ada query.
            if (query.trim() && context.canCreate) openDropdownWith([], query);
        }
    }

    function scheduleFetch(query) {
        if (debounce) clearTimeout(debounce);
        // Ketik 2-3 huruf → fetch debounced filter server-side. Fokus pun fetch
        // dengan q kosong agar seluruh katalog aktif terlihat.
        debounce = setTimeout(() => fetchAndShow(query), 180);
    }

    input.addEventListener('input', () => {
        if (!input.value.trim() && !context.canCreate) { closeDropdown(); return; }
        scheduleFetch(input.value);
    });

    input.addEventListener('focus', () => {
        // Tampilkan semua (q="") saat baru fokus bila user belum ketik — biar katalog tersebar terlihat.
        scheduleFetch(input.value || '');
    });

    input.addEventListener('keydown', (e) => {
        if (e.key === 'Escape' && dropdown) { closeDropdown(); e.stopPropagation(); }
    });

    row._productPickerClose = closeDropdown;
}

export function initProductPickers() {
    const itemsBody = document.getElementById('items-body');
    if (!itemsBody) return;

    const csrfMeta = document.querySelector('meta[name="csrf-token"]');
    const csrf = csrfMeta ? csrfMeta.content : '';
    const serverBootstrap = (() => {
        const el = document.getElementById('picker-bootstrap');
        if (!el) return [];
        try { const v = JSON.parse(el.textContent || '[]'); return Array.isArray(v) ? v : []; } catch { return []; }
    })();
    // quick-create disahkan di sisi server (route protects); tapi UI menyembunyikan opsi bila user tanpa products.manage.
    const bodyEl = document.body;
    const canCreate = document.documentElement.dataset.canCreateProducts === '1'
        || (bodyEl && bodyEl.dataset.canCreateProducts === '1')
        || Boolean(document.querySelector('[data-can-create-products="1"]'));

    // Produk lokal = bootstrap + hasil quick-create (tanpa refetch).Dipakai untuk pick tanpa jaringan.
    const localProducts = Array.isArray(serverBootstrap) ? serverBootstrap.slice() : [];

    function addLocalProduct(product) {
        // unshift supaya muncul di awal dropdown; dedupe by id.
        const i = localProducts.findIndex((p) => String(p.id) === String(product.id));
        if (i >= 0) localProducts.splice(i, 1);
        localProducts.unshift(product);
    }

    // Quick-create dialog events
    let quickCreatePendingResolve = null;
    let quickCreatePendingRow = null;

    function getQuickDialog() {
        const d = document.getElementById('picker-quick-create');
        return d;
    }

    function onQuickCreateConfirm() {
        const d = getQuickDialog();
        if (!d) return;
        const nameVal = (d.querySelector('[data-quick-name]')?.value || '').trim();
        const priceVal = (d.querySelector('[data-quick-price]')?.value || '').trim() || '0';
        const categoryVal = (d.querySelector('[data-quick-category]')?.value || '').trim();
        if (!nameVal) {
            const err = d.querySelector('[data-quick-error]');
            if (err) err.textContent = 'Nama produk wajib diisi.';
            return;
        }
        confirmQuickCreate(nameVal, priceVal, categoryVal, quickCreatePendingRow);
    }

    // Bind dialog buttons sekali
    (() => {
        const d = getQuickDialog();
        if (!d) return;
        d.querySelector('[data-role=quick-confirm]')?.addEventListener('click', onQuickCreateConfirm);
        d.querySelector('[data-role=quick-cancel]')?.addEventListener('click', () => {
            const pendingRow = quickCreatePendingRow;
            const rr = quickCreatePendingResolve;
            quickCreatePendingRow = null; quickCreatePendingResolve = null;
            d.close();
            if (rr) rr(null);
        });
        d.addEventListener('close', () => {
            // Bila ditutup tanpa konfirmasi (Esc/overlay), anggap batal.
            if (quickCreatePendingResolve) {
                const rr = quickCreatePendingResolve; quickCreatePendingResolve = null; quickCreatePendingRow = null; rr(null);
            }
        });
        const nameField = d.querySelector('[data-quick-name]');
        nameField?.addEventListener('keydown', (e) => {
            if (e.key === 'Enter') { e.preventDefault(); onQuickCreateConfirm(); }
        });
    })();

    async function confirmQuickCreate(nameVal, priceStr, categoryId, pendingRow) {
        const d = getQuickDialog();
        const errEl = d ? d.querySelector('[data-quick-error]') : null;
        const confirmBtn = d ? d.querySelector('[data-role=quick-confirm]') : null;
        if (errEl) errEl.textContent = '';
        // Kunci tombol selama request agar tidak double-create.
        if (confirmBtn) confirmBtn.disabled = true;
        const payload = {
            name: nameVal,
            sales_price: priceStr ? Number(priceStr) : 0,
            category_id: categoryId ? Number(categoryId) : null,
        };
        if (!Number.isFinite(payload.sales_price)) payload.sales_price = 0;
        const rowRef = pendingRow || quickCreatePendingRow;
        const rr = quickCreatePendingResolve;
        quickCreatePendingRow = null; quickCreatePendingResolve = null;
        try {
            const res = await fetch(ENDPOINT_CREATE, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    Accept: 'application/json',
                    'X-CSRF-TOKEN': csrf,
                },
                body: JSON.stringify(payload),
            });
            if (!res.ok) {
                let msg = 'Gagal menyimpan produk.';
                try {
                    const j = await res.json();
                    if (j.errors && j.errors.name) msg = j.errors.name.join(' ');
                    else if (j.message) msg = j.message;
                } catch { }
                throw new Error(msg);
            }
            const j = await res.json();
            const product = j.product || j;
            if (d) d.close();
            addLocalProduct(product);
            // Terapkan ke baris asal
            if (rowRef && product.name) {
                const searchInput = rowRef.querySelector('[data-role=product-search]');
                if (searchInput) { searchInput.value = product.name; searchInput.setAttribute('data-selected-name', product.name); }
                const mapped = productPayloadToRowOptions(product);
                applySelectionToRow(rowRef, mapped, null);
            }
            if (rr) rr(product);
        } catch (e) {
            if (errEl) errEl.textContent = e.message || 'Gagal menyimpan produk.';
            // buka kembali untuk diperbaiki
            if (d && !d.open) d.showModal();
            quickCreatePendingRow = rowRef; quickCreatePendingResolve = rr;
        } finally {
            if (confirmBtn) confirmBtn.disabled = false;
        }
    }

    async function requestQuickCreate(initialName, row) {
        const d = getQuickDialog();
        if (!d) return null;
        d.querySelector('[data-quick-name]').value = initialName;
        d.querySelector('[data-quick-price]').value = '0';
        const catSel = d.querySelector('[data-quick-category]');
        if (catSel) catSel.value = '';
        const errEl = d.querySelector('[data-quick-error]');
        if (errEl) errEl.textContent = '';
        const p = new Promise((resolve) => { quickCreatePendingResolve = resolve; quickCreatePendingRow = row; });
        d.showModal();
        // fokus input nama
        setTimeout(() => d.querySelector('[data-quick-name]')?.focus(), 10);
        return p;
    }

    const context = {
        csrf,
        canCreate,
        localProducts,
        requestQuickCreate,
        addLocalProduct,
    };

    // Pasang controller ke setiap baris yang ada saat ini, dan setiap baris yang ditambahkan setelahnya.
    for (const row of itemsBody.querySelectorAll('tr.item-row')) attachRowPicker(row, context);

    // Didelegasikan: row yang ditambahkan lewat tombol "Tambah baris" atau "Isi dari layanan"
    const mo = new MutationObserver((mutations) => {
        for (const mut of mutations) {
            for (const node of mut.addedNodes) {
                if (node.matches && node.matches('tr.item-row')) attachRowPicker(node, context);
                if (node.querySelectorAll) for (const r of node.querySelectorAll('tr.item-row')) attachRowPicker(r, context);
            }
        }
    });
    mo.observe(itemsBody, { childList: true, subtree: false });

    // Klik di luar menutup dropdown
    document.addEventListener('click', (e) => {
        const open = document.querySelector('[data-picker-dropdown]');
        if (!open) return;
        const row = open.closest('[data-role=product-search-box]')?.closest('tr.item-row') || open.closest('tr.item-row');
        if (row && (e.target.closest('[data-picker-dropdown]') || e.target.closest('[data-role=product-search]'))) return;
        if (!row || !open.contains(e.target)) open.remove();
    });

    // -- Split button simpan invoice (default → draf) --
    // Menu opsi adalah tombol submit dengan name="save_action" + value sendiri,
    // jadi nilai terakhir-dikliklah yang terkirim (default: tombol utama = draft).
    const splitRoot = document.getElementById('invoice-save-split');
    if (splitRoot) {
        const menu = splitRoot.querySelector('[data-save-menu]');
        const toggle = splitRoot.querySelector('[data-save-toggle]');

        toggle?.addEventListener('click', () => {
            if (!menu) return;
            menu.hidden = !menu.hidden;
            toggle.setAttribute('aria-expanded', menu.hidden ? 'false' : 'true');
        });

        document.addEventListener('click', (e) => {
            if (!menu || menu.hidden) return;
            if (!splitRoot.contains(e.target)) {
                menu.hidden = true;
                toggle?.setAttribute('aria-expanded', 'false');
            }
        });

        menu?.addEventListener('click', () => {
            menu.hidden = true;
            toggle?.setAttribute('aria-expanded', 'false');
        });
    }
}
