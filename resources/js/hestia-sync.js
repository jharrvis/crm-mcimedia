// Sinkronisasi HestiaCP bertahap via AJAX (t_dcccffd9).
//
// Tombol "Sinkron" pada halaman Server Hestia memakai modul ini: proses sync
// dipecah server-side menjadi beberapa batch (default 10 akun per batch, lihat
// HestiaBatchSyncService), lalu modul ini memanggil batch-batch itu berurutan
// sambil memperbarui progress bar — jadi tidak ada satu request panjang yang
// bisa menabrak gateway timeout.
//
// Desain:
//  1. `POST {start-url}`  → membuat sesi, server menarik daftar akun sekali
//     dan mengembalikan total akun (sumber angka progress).
//  2. `POST {batch-url}` dengan `batch_id` → memproses ≤ batch_size akun
//     berikutnya; diulang sampai `done: true`.
//  3. Hasil akhir (sukses/gagal + jumlah akun tersinkron) ditampilkan tanpa
//     reload halaman; tombol dinonaktifkan selama proses.
//
// Setiap request memakai timeout ≥ 60 detik (default 120 detik) dan diulang
// otomatis saat timeout/error jaringan — batch bersifat idempotent di server,
// jadi retry hanya melanjutkan dari posisi terakhir.
//
// Fungsi murni diekspor supaya bisa diuji dengan `node --test`
// (lihat tests/JS/hestia-sync.test.mjs).

export const DEFAULT_TIMEOUT_MS = 120000; // ≥ 60 dtk: batch di server bisa lambat
export const MAX_RETRIES = 2; // percobaan ulang per request (di luar percobaan pertama)
export const RETRY_DELAY_MS = 2000;
export const MAX_SHOWN_ERRORS = 10;

/** Kelas error untuk kegagalan yang tidak perlu diulang (4xx dari server). */
export class PermanentError extends Error {}

/** Persentase 0–100; total 0 (server tanpa akun) dianggap selesai. */
export function progressPercent(processed, total) {
    if (!Number.isFinite(total) || total <= 0) {
        return 100;
    }

    const clamped = Math.min(Math.max(Number(processed) || 0, 0), total);

    return Math.round((clamped / total) * 100);
}

/** Teks status saat berjalan, mis. "Menyinkronkan akun 20/59… 34%". */
export function progressLabel(processed, total) {
    const safeTotal = Number.isFinite(total) && total > 0 ? total : 0;
    const shown = Math.min(Math.max(Number(processed) || 0, 0), safeTotal);

    return `Menyinkronkan akun ${shown}/${safeTotal}… ${progressPercent(shown, safeTotal)}%`;
}

/** Rangkuman counter untuk baris detail, mis. "240 domain ditarik, 12 baru, 228 diperbarui". */
export function totalsLabel(totals = {}) {
    const pulled = Number(totals.pulled) || 0;
    const created = Number(totals.created) || 0;
    const updated = Number(totals.updated) || 0;
    const deactivated = Number(totals.deactivated) || 0;

    const parts = [`${pulled} domain ditarik`, `${created} baru`, `${updated} diperbarui`];

    if (deactivated > 0) {
        parts.push(`${deactivated} dinonaktifkan`);
    }

    return parts.join(', ');
}

/** Label akun gagal untuk daftar error, mis. "budi — Hestia API: …". */
export function errorLabel(error) {
    const user = String(error?.user ?? '').trim() || 'akun';
    const message = String(error?.message ?? '').trim() || 'gagal ditarik';

    return `${user} — ${message}`;
}

/**
 * Satu request JSON dengan timeout & retry.
 *
 * - Timeout memakai AbortController (default 120 dtk, selalu ≥ 60 dtk).
 * - Status 404/422 = kesalahan tetap (tidak diulang), dilempar sebagai
 *   PermanentError; error lain (timeout, 5xx, jaringan) diulang sampai
 *   `maxRetries` kali dengan jeda `retryDelayMs`.
 *
 * @param {object} [options] fetchImpl, csrfToken, timeoutMs, maxRetries,
 *                           retryDelayMs, sleep — bisa diganti saat tes.
 * @returns {Promise<object>} payload JSON `{ ok: true, ... }` dari server.
 */
export async function postJson(url, payload, options = {}) {
    const {
        fetchImpl = (...args) => fetch(...args),
        csrfToken = '',
        timeoutMs = DEFAULT_TIMEOUT_MS,
        maxRetries = MAX_RETRIES,
        retryDelayMs = RETRY_DELAY_MS,
        sleep = (ms) => new Promise((resolve) => setTimeout(resolve, ms)),
    } = options;

    let lastError = null;

    for (let attempt = 0; attempt <= maxRetries; attempt += 1) {
        try {
            return await postJsonOnce(url, payload, { fetchImpl, csrfToken, timeoutMs });
        } catch (error) {
            if (error instanceof PermanentError) {
                throw error;
            }

            lastError = error;

            if (attempt < maxRetries) {
                await sleep(retryDelayMs);
            }
        }
    }

    throw lastError;
}

async function postJsonOnce(url, payload, { fetchImpl, csrfToken, timeoutMs }) {
    const controller = new AbortController();
    const timer = setTimeout(() => controller.abort(), timeoutMs);

    let response;

    try {
        response = await fetchImpl(url, {
            method: 'POST',
            headers: {
                Accept: 'application/json',
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': csrfToken,
                'X-Requested-With': 'XMLHttpRequest',
            },
            body: JSON.stringify(payload ?? {}),
            signal: controller.signal,
        });
    } catch (error) {
        // AbortError = kita yang membatalkan (timeout); selain itu jaringan.
        throw new Error(
            error?.name === 'AbortError'
                ? `Request melebihi ${Math.round(timeoutMs / 1000)} detik.`
                : 'Koneksi ke server gagal.',
        );
    } finally {
        clearTimeout(timer);
    }

    let data = null;
    try {
        data = await response.json();
    } catch {
        data = null;
    }

    if (response.status === 404 || response.status === 422) {
        throw new PermanentError(data?.error || `Permintaan ditolak (HTTP ${response.status}).`);
    }

    if (!response.ok) {
        throw new Error(data?.error || `Server membalas HTTP ${response.status}.`);
    }

    if (data === null || data.ok !== true) {
        throw new Error(data?.error || 'Respons server tidak dikenali.');
    }

    return data;
}

/**
 * Jalankan satu sesi sinkronisasi bertahap sampai selesai.
 *
 * @param {object} params startUrl, batchUrl, options (untuk postJson),
 *                        onProgress(state) dipanggil tiap respons.
 * @returns {Promise<object>} state batch terakhir (done: true).
 */
export async function runBatchSync({ startUrl, batchUrl, options = {}, onProgress = null }) {
    const start = await postJson(startUrl, {}, options);
    let state = start.batch;
    onProgress?.(state);

    while (!state.done) {
        const response = await postJson(batchUrl, { batch_id: state.id }, options);
        state = response.batch;
        onProgress?.(state);
    }

    return state;
}

/**
 * Pasang handler tombol sync pada halaman Server Hestia (index/show).
 *
 * Idempotent: aman dipanggil berkali-kali — halaman tanpa panel/tombol
 * batch dilewati begitu saja (tombol sync halaman lain tidak tersentuh).
 *
 * @returns {{ run: (button: Element) => Promise<void> } | null}
 */
export function initHestiaSync(root = document) {
    const panel = root.querySelector('[data-hestia-sync-panel]');
    const buttons = Array.from(root.querySelectorAll('[data-hestia-sync]'));

    if (!panel || buttons.length === 0 || panel.dataset.syncReady === '1') {
        return null;
    }

    panel.dataset.syncReady = '1';

    const els = {
        status: panel.querySelector('[data-sync-status]'),
        detail: panel.querySelector('[data-sync-detail]'),
        bar: panel.querySelector('[data-sync-bar]'),
        spinner: panel.querySelector('[data-sync-spinner]'),
        errors: panel.querySelector('[data-sync-errors]'),
        dismiss: panel.querySelector('[data-sync-dismiss]'),
        reload: panel.querySelector('[data-sync-reload]'),
    };

    let running = false;

    const csrfToken = () =>
        root.querySelector('meta[name="csrf-token"]')?.content ?? '';

    /** Atur lebar + aria progress bar (0–100). */
    const setBar = (percent) => {
        if (!els.bar) return;

        els.bar.style.width = `${percent}%`;
        els.bar.setAttribute('aria-valuenow', String(percent));
    };

    const renderErrors = (errorList) => {
        if (!els.errors) return;

        const errors = Array.isArray(errorList) ? errorList : [];
        els.errors.textContent = '';

        if (errors.length === 0) {
            els.errors.classList.add('hidden');
            return;
        }

        els.errors.classList.remove('hidden');

        errors.slice(0, MAX_SHOWN_ERRORS).forEach((error) => {
            const item = document.createElement('li');
            item.textContent = errorLabel(error);
            els.errors.appendChild(item);
        });

        if (errors.length > MAX_SHOWN_ERRORS) {
            const rest = document.createElement('li');
            rest.textContent = `+${errors.length - MAX_SHOWN_ERRORS} akun lain gagal (lihat riwayat sinkronisasi).`;
            els.errors.appendChild(rest);
        }
    };

    const render = (batch) => {
        const totals = batch.totals || {};
        const serverName = batch.server?.name || 'server';

        if (!batch.done) {
            panel.dataset.state = 'running';
            if (els.status) els.status.textContent = progressLabel(batch.processed_users, batch.total_users);
            if (els.detail) els.detail.textContent = `Server: ${serverName} • ${totalsLabel(totals)}`;
            setBar(batch.percent ?? progressPercent(batch.processed_users, batch.total_users));
            renderErrors(batch.errors);
            return;
        }

        // Selesai — teks hasil akhir dirangkai server (satu sumber kebenaran);
        // fallback ke rangkuman klien bila server tidak mengirim pesan.
        const success = batch.status === 'success';
        panel.dataset.state = success ? 'success' : 'failed';
        setBar(100);
        if (els.status) {
            els.status.textContent = success
                ? batch.message || `Sinkronisasi selesai: ${totalsLabel(totals)}.`
                : batch.message || 'Sinkronisasi gagal.';
        }
        if (els.detail) els.detail.textContent = `Server: ${serverName} • ${totalsLabel(totals)}`;
        renderErrors(batch.errors);
    };

    const setRunning = (value) => {
        running = value;
        // Semua tombol sync dinonaktifkan selama proses: cegah double-click
        // sekaligus cegah dua sesi paralel untuk server yang sama.
        buttons.forEach((button) => {
            button.disabled = value;
        });
        els.spinner?.classList.toggle('hidden', !value);
    };

    const blockUnload = (event) => {
        event.preventDefault();
        event.returnValue = '';
    };

    const run = async (button) => {
        if (running) {
            return;
        }

        setRunning(true);
        panel.classList.remove('hidden');
        panel.dataset.state = 'running';
        els.dismiss?.classList.add('hidden');
        els.reload?.classList.add('hidden');
        if (els.status) els.status.textContent = 'Menyiapkan daftar akun…';
        if (els.detail) els.detail.textContent = `Server: ${button.dataset.syncServer || 'server'}`;
        if (els.bar) els.bar.style.width = '0%';
        renderErrors([]);
        window.addEventListener('beforeunload', blockUnload);

        try {
            await runBatchSync({
                startUrl: button.dataset.syncStartUrl,
                batchUrl: button.dataset.syncBatchUrl,
                options: { csrfToken: csrfToken() },
                onProgress: render,
            });
        } catch (error) {
            panel.dataset.state = 'failed';
            if (els.spinner) els.spinner.classList.add('hidden');
            if (els.status) els.status.textContent = `Sinkronisasi terhenti: ${error.message}`;
            if (els.detail) {
                els.detail.textContent = 'Proses bersifat idempotent — klik Sinkron untuk mengulang dari awal.';
            }
        } finally {
            setRunning(false);
            window.removeEventListener('beforeunload', blockUnload);
            els.dismiss?.classList.remove('hidden');
            els.reload?.classList.remove('hidden');
        }
    };

    els.dismiss?.addEventListener('click', () => panel.classList.add('hidden'));

    buttons.forEach((button) => {
        button.addEventListener('click', () => run(button));
    });

    return { run };
}
