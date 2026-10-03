// Pencarian klien per-opsi untuk <x-client-select> (UX-4).
// Markup: resources/views/components/client-select.blade.php.
//
// <select> tetap native dan jadi sumber kebenaran nilai form. Filter bekerja
// dengan melepas opsi yang tidak cocok dari DOM (bukan option.hidden — Chrome
// tetap menampilkannya) lalu menyusunnya kembali dari salinan yang disimpan.
// Opsi terpilih SELALU dipertahankan supaya nilai form tidak pernah berubah
// diam-diam saat admin mengetik (skrip dependent membaca select.value).

export function matchesClient(query, name, contact = '') {
    const q = query.trim().toLowerCase();
    if (q === '') return true;

    return name.toLowerCase().includes(q) || contact.toLowerCase().includes(q);
}

function init(root) {
    if (root.dataset.ready === '1') return;
    root.dataset.ready = '1';

    const input = root.querySelector('[data-client-search]');
    const select = root.querySelector('select[name]');
    const count = root.querySelector('[data-client-count]');
    if (!input || !select) return;

    // Salinan opsi (tanpa placeholder) + label untuk dicocokkan.
    const all = Array.from(select.options)
        .filter((opt) => opt.value !== '')
        .map((opt) => ({ opt, name: opt.textContent.trim(), contact: opt.dataset.contact || '' }));

    function apply() {
        const query = input.value;
        const selected = select.value;
        let shown = 0;

        all.forEach(({ opt, name, contact }) => {
            const keep = matchesClient(query, name, contact) || opt.value === selected;
            if (keep) {
                if (opt.parentNode !== select) select.appendChild(opt);
                shown++;
            } else if (opt.parentNode === select) {
                opt.remove();
            }
        });

        if (count) {
            count.hidden = query.trim() === '';
            count.textContent = query.trim() === ''
                ? ''
                : `${shown} klien cocok dengan “${query.trim()}”`;
        }
    }

    input.addEventListener('input', apply);
    input.addEventListener('search', apply); // tombol hapus bawaan browser
    select.addEventListener('change', () => {
        // Pilihan manual setelah filter aktif: selaraskan ulang supaya opsi
        // terpilih tidak disembunyikan oleh filter berikutnya.
        apply();
    });
}

export function initClientSelects() {
    document.querySelectorAll('[data-client-select]').forEach(init);
}

initClientSelects();
document.addEventListener('DOMContentLoaded', initClientSelects);