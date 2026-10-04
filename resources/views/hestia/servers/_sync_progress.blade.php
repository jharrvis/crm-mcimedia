{{-- Panel progres sinkronisasi bertahap via AJAX (t_dcccffd9).
     Diisi & dikendalikan resources/js/hestia-sync.js — tampil saat tombol
     "Sinkron" diklik, memperbarui progress bar per batch, lalu menampilkan
     hasil akhir tanpa reload. Aman di-include sekali per halaman;
     atribut `data-state` (running/success/failed) mengganti warna. --}}
<noscript>
    {{-- Tanpa JavaScript tombol AJAX tidak berfungsi — sembunyikan agar hanya
         form POST fallback yang terlihat. --}}
    <style>[data-hestia-sync] { display: none !important; }</style>
</noscript>
<div data-hestia-sync-panel
     data-state="idle"
     class="group mb-4 hidden rounded-xl border border-indigo-200 bg-white p-4 shadow-sm data-[state=success]:border-green-300 data-[state=failed]:border-red-300 dark:border-indigo-950 dark:bg-slate-900 dark:data-[state=success]:border-green-900 dark:data-[state=failed]:border-red-900"
     role="status" aria-live="polite">
    <div class="flex items-start gap-3">
        <span data-sync-spinner aria-hidden="true"
              class="mt-1 hidden h-4 w-4 shrink-0 animate-spin rounded-full border-2 border-indigo-600 border-t-transparent dark:border-indigo-300 dark:border-t-transparent"></span>
        <div class="min-w-0 flex-1">
            <p data-sync-status class="font-semibold text-slate-800 dark:text-slate-100">Menyiapkan…</p>
            <div class="mt-2 h-2 w-full overflow-hidden rounded-full bg-slate-200 dark:bg-slate-700">
                <div data-sync-bar style="width:0%"
                     role="progressbar" aria-label="Progres sinkronisasi" aria-valuemin="0" aria-valuemax="100" aria-valuenow="0"
                     class="h-full w-0 rounded-full bg-indigo-600 transition-all duration-300 group-data-[state=success]:bg-green-600 group-data-[state=failed]:bg-red-600"></div>
            </div>
            <p data-sync-detail class="mt-1.5 text-xs text-slate-500 dark:text-slate-400"></p>
            <ul data-sync-errors class="mt-2 hidden list-inside list-disc space-y-0.5 text-xs text-red-600 dark:text-red-400"></ul>
            <a data-sync-reload href="{{ url()->current() }}" class="mt-2 hidden text-xs font-semibold text-indigo-600 hover:underline">
                Muat ulang halaman untuk melihat angka terbaru
            </a>
        </div>
        <button type="button" data-sync-dismiss
                class="hidden shrink-0 text-xs font-semibold text-slate-500 hover:underline dark:text-slate-400">Tutup</button>
    </div>
</div>
