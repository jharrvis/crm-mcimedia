// Test logika sinkronisasi Hestia bertahap (t_dcccffd9) — murni + wiring DOM.
// Jalankan: node --test tests/JS/hestia-sync.test.mjs
import assert from 'node:assert/strict';
import { test } from 'node:test';

import {
    DEFAULT_TIMEOUT_MS,
    PermanentError,
    errorLabel,
    initHestiaSync,
    postJson,
    progressLabel,
    progressPercent,
    runBatchSync,
    totalsLabel,
} from '../../resources/js/hestia-sync.js';

// ---------------------------------------------------------------- helpers

const jsonResponse = (status, data) => ({
    ok: status >= 200 && status < 300,
    status,
    json: async () => data,
});

const flush = () => new Promise((resolve) => setImmediate(resolve));

function fakeEl(extra = {}) {
    const classes = new Set();
    const attributes = {};
    const el = {
        dataset: {},
        style: {},
        textContent: '',
        disabled: false,
        children: [],
        listeners: {},
        setAttribute(name, value) {
            attributes[name] = String(value);
        },
        getAttribute(name) {
            return attributes[name] ?? null;
        },
        classList: {
            add: (c) => classes.add(c),
            remove: (c) => classes.delete(c),
            contains: (c) => classes.has(c),
            toggle: (c, force) => (force ? classes.add(c) : classes.delete(c)),
        },
        addEventListener(type, fn) {
            (this.listeners[type] ||= []).push(fn);
        },
        removeEventListener() {},
        appendChild(child) {
            this.children.push(child);
        },
        querySelector: () => null,
        ...extra,
    };

    return el;
}

/** Root+panel palsu untuk initHestiaSync; mengembalikan referensi elemen. */
function makeFakePage(buttonCount = 2) {
    const els = {
        status: fakeEl(),
        detail: fakeEl(),
        bar: fakeEl(),
        spinner: fakeEl(),
        errors: fakeEl(),
        dismiss: fakeEl(),
        reload: fakeEl(),
    };

    const panel = fakeEl({ dataset: { state: 'idle' } });

    // Nama atribut di Blade: [data-sync-status] dst. — samakan pemetaannya.
    panel.querySelector = (sel) => {
        const key = sel.replace('[data-sync-', '').replace(']', '');

        return els[key] ?? null;
    };

    const buttons = Array.from({ length: buttonCount }, () =>
        fakeEl({
            dataset: {
                syncStartUrl: '/hestia/servers/1/sync/start',
                syncBatchUrl: '/hestia/servers/1/sync/batch',
                syncServer: 'sg2',
            },
        }),
    );

    const csrf = fakeEl({ content: 'csrf-test-token' });

    const root = {
        querySelector: (sel) =>
            sel === '[data-hestia-sync-panel]' ? panel : sel === 'meta[name="csrf-token"]' ? csrf : null,
        querySelectorAll: (sel) => (sel === '[data-hestia-sync]' ? buttons : []),
    };

    return { root, panel, els, buttons };
}

// ------------------------------------------------------------- fungsi murni

test('progressPercent 0–100 dan aman untuk total tidak diketahui', () => {
    assert.equal(progressPercent(0, 59), 0);
    assert.equal(progressPercent(20, 59), 34);
    assert.equal(progressPercent(59, 59), 100);
    assert.equal(progressPercent(0, 0), 100); // server tanpa akun = selesai
    assert.equal(progressPercent(999, 10), 100); // nilai ekstrem dibatasi
    assert.equal(progressPercent(-5, 10), 0);
    assert.equal(progressPercent(3, Number.NaN), 100);
});

test('progressLabel memakai format "Menyinkronkan akun 20/59… 34%"', () => {
    assert.equal(progressLabel(20, 59), 'Menyinkronkan akun 20/59… 34%');
    assert.equal(progressLabel(0, 59), 'Menyinkronkan akun 0/59… 0%');
    assert.equal(progressLabel(59, 59), 'Menyinkronkan akun 59/59… 100%');
});

test('totalsLabel merangkum counter dan hanya menyebut deaktivasi bila ada', () => {
    assert.equal(
        totalsLabel({ pulled: 240, created: 12, updated: 228, deactivated: 0 }),
        '240 domain ditarik, 12 baru, 228 diperbarui',
    );
    assert.equal(
        totalsLabel({ pulled: 240, created: 12, updated: 227, deactivated: 1 }),
        '240 domain ditarik, 12 baru, 227 diperbarui, 1 dinonaktifkan',
    );
    assert.equal(totalsLabel(), '0 domain ditarik, 0 baru, 0 diperbarui');
});

test('errorLabel menampilkan nama akun dan pesannya', () => {
    assert.equal(errorLabel({ user: 'budi', message: 'API timeout' }), 'budi — API timeout');
    assert.equal(errorLabel({}), 'akun — gagal ditarik');
});

test('DEFAULT_TIMEOUT_MS memenuhi syarat minimal 60 detik', () => {
    assert.ok(DEFAULT_TIMEOUT_MS >= 60000, 'timeout batch minimal 60 dtk');
});

// ------------------------------------------------------------------ postJson

test('postJson mengirim CSRF header + body JSON dan mengembalikan payload', async () => {
    const calls = [];
    const fetchImpl = async (url, options) => {
        calls.push({ url, options });

        return jsonResponse(200, { ok: true, batch: { id: 1 } });
    };

    const data = await postJson('/x/start', { batch_size: 10 }, { fetchImpl, csrfToken: 'tok' });

    assert.equal(data.batch.id, 1);
    assert.equal(calls.length, 1);
    assert.equal(calls[0].options.method, 'POST');
    assert.equal(calls[0].options.headers['X-CSRF-TOKEN'], 'tok');
    assert.equal(calls[0].options.body, JSON.stringify({ batch_size: 10 }));
    assert.ok(calls[0].options.signal, 'memakai AbortController (timeout)');
});

test('postJson mengulang request timeout/5xx lalu berhasil', async () => {
    let attempts = 0;
    const sleeps = [];
    const fetchImpl = async () => {
        attempts += 1;

        return attempts < 3 ? jsonResponse(500, {}) : jsonResponse(200, { ok: true, n: attempts });
    };

    const data = await postJson('/x', {}, {
        fetchImpl,
        retryDelayMs: 1234,
        sleep: async (ms) => sleeps.push(ms),
    });

    assert.equal(data.n, 3);
    assert.equal(attempts, 3);
    assert.deepEqual(sleeps, [1234, 1234]);
});

test('postJson TIDAK mengulang penolakan 404/422 (PermanentError)', async () => {
    let attempts = 0;
    const fetchImpl = async () => {
        attempts += 1;

        return jsonResponse(422, { ok: false, error: 'Kredensial belum lengkap.' });
    };

    await assert.rejects(
        () => postJson('/x', {}, { fetchImpl }),
        (error) => error instanceof PermanentError && error.message === 'Kredensial belum lengkap.',
    );
    assert.equal(attempts, 1);
});

test('postJson menyerah setelah maxRetries dan melempar error terakhir', async () => {
    let attempts = 0;
    const fetchImpl = async () => {
        attempts += 1;

        return jsonResponse(500, { error: 'DB down' });
    };

    await assert.rejects(() => postJson('/x', {}, { fetchImpl, maxRetries: 2, sleep: async () => {} }));
    assert.equal(attempts, 3); // 1 percobaan + 2 ulangan
});

test('postJson mengubah abort (timeout) menjadi pesan yang manusiawi', async () => {
    const fetchImpl = async () => {
        const error = new Error('aborted');
        error.name = 'AbortError';
        throw error;
    };

    await assert.rejects(
        () => postJson('/x', {}, { fetchImpl, maxRetries: 0, timeoutMs: 90000 }),
        /Request melebihi 90 detik/,
    );
});

test('postJson mengubah error jaringan menjadi pesan koneksi', async () => {
    const fetchImpl = async () => {
        throw new TypeError('fetch failed');
    };

    await assert.rejects(
        () => postJson('/x', {}, { fetchImpl, maxRetries: 0 }),
        /Koneksi ke server gagal/,
    );
});

// -------------------------------------------------------------- runBatchSync

test('runBatchSync memanggil start lalu batch berulang sampai done', async () => {
    const calls = [];
    const progress = [];
    const steps = [
        { ok: true, batch: { id: 7, done: false, total_users: 25, processed_users: 0, status: 'running', totals: {}, errors: [] } },
        { ok: true, batch: { id: 7, done: false, total_users: 25, processed_users: 10, status: 'running', totals: { pulled: 10 }, errors: [] } },
        { ok: true, batch: { id: 7, done: false, total_users: 25, processed_users: 20, status: 'running', totals: { pulled: 20 }, errors: [] } },
        { ok: true, batch: { id: 7, done: true, total_users: 25, processed_users: 25, status: 'success', totals: { pulled: 25 }, errors: [], message: 'Sinkronisasi selesai.' } },
    ];
    let index = 0;
    const fetchImpl = async (url, options) => {
        calls.push({ url, body: JSON.parse(options.body) });

        return jsonResponse(200, steps[index++]);
    };

    const final = await runBatchSync({
        startUrl: '/hestia/servers/1/sync/start',
        batchUrl: '/hestia/servers/1/sync/batch',
        options: { fetchImpl, sleep: async () => {} },
        onProgress: (state) => progress.push(state.processed_users),
    });

    assert.equal(calls.length, 4);
    assert.equal(calls[0].url, '/hestia/servers/1/sync/start');
    assert.deepEqual(calls[0].body, {});
    assert.deepEqual(calls.slice(1).map((c) => c.url), ['/hestia/servers/1/sync/batch', '/hestia/servers/1/sync/batch', '/hestia/servers/1/sync/batch']);
    assert.deepEqual(calls.slice(1).map((c) => c.body), [{ batch_id: 7 }, { batch_id: 7 }, { batch_id: 7 }]);
    assert.deepEqual(progress, [0, 10, 20, 25]);
    assert.equal(final.done, true);
    assert.equal(final.status, 'success');
});

// -------------------------------------------------------------------- DOM

test('initHestiaSync menonaktifkan semua tombol selama proses lalu menampilkan hasil', async () => {
    globalThis.window = { addEventListener() {}, removeEventListener() {} };
    globalThis.document = { createElement: () => fakeEl() };

    const { root, panel, els, buttons } = makeFakePage(2);

    let release;
    const gate = new Promise((resolve) => {
        release = resolve;
    });
    const startPayload = {
        ok: true,
        batch: { id: 3, done: false, total_users: 2, processed_users: 0, status: 'running', totals: {}, errors: [], server: { name: 'sg2' } },
    };
    const donePayload = {
        ok: true,
        batch: {
            id: 3, done: true, total_users: 2, processed_users: 2, status: 'success',
            totals: { pulled: 5, created: 2, updated: 3, deactivated: 0 },
            errors: [], message: 'Sinkronisasi selesai: 2 akun tersinkron, 5 domain ditarik (2 baru, 3 diperbarui).',
            server: { name: 'sg2' },
        },
    };
    const steps = [() => gate.then(() => jsonResponse(200, startPayload)), () => jsonResponse(200, donePayload)];
    let index = 0;
    globalThis.fetch = (...args) => {
        void args;

        return steps[Math.min(index++, steps.length - 1)]();
    };

    const api = initHestiaSync(root);
    assert.ok(api, 'init mengembalikan API pada halaman dengan tombol sync');
    assert.equal(initHestiaSync(root), null, 'init kedua kali idempotent (tidak menggandakan handler)');

    const runPromise = api.run(buttons[0]);
    await flush();

    // try/finally: gate WAJIB dilepas walau assertion gagal, supaya proses
    // node tidak menggantung menunggu timer request.
    try {
        assert.equal(buttons[0].disabled, true, 'tombol aktif dinonaktifkan selama proses');
        assert.equal(buttons[1].disabled, true, 'semua tombol dinonaktifkan selama proses');
        assert.equal(panel.dataset.state, 'running');
        assert.equal(els.spinner.classList.contains('hidden'), false, 'spinner tampil saat berjalan');
    } finally {
        release();
    }

    await runPromise;

    assert.equal(buttons[0].disabled, false, 'tombol aktif kembali setelah selesai');
    assert.equal(buttons[1].disabled, false);
    assert.equal(panel.dataset.state, 'success');
    assert.equal(els.bar.style.width, '100%');
    assert.equal(els.bar.getAttribute('aria-valuenow'), '100');
    assert.equal(els.spinner.classList.contains('hidden'), true, 'spinner disembunyikan setelah selesai');
    assert.match(els.status.textContent, /Sinkronisasi selesai: 2 akun tersinkron/);
    assert.match(els.detail.textContent, /5 domain ditarik, 2 baru, 3 diperbarui/);
});

test('initHestiaSync melewati halaman tanpa panel/tombol batch', () => {
    globalThis.window = { addEventListener() {}, removeEventListener() {} };
    globalThis.document = { createElement: () => fakeEl() };

    const emptyRoot = { querySelector: () => null, querySelectorAll: () => [] };

    assert.equal(initHestiaSync(emptyRoot), null);
});
