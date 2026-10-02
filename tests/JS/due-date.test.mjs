// Test matematika preset jatuh tempo invoice (UX-3).
// Jalankan: node --test tests/JS/due-date.test.mjs
import assert from 'node:assert/strict';
import { test } from 'node:test';

import {
    PRESET_KEYS,
    addDays,
    daysBetween,
    presetDate,
    presetKeyFor,
    toDisplay,
    toLocal,
    toYmd,
} from '../../resources/js/due-date.js';

test('setiap preset menghasilkan hari ini + N hari', () => {
    const today = '2026-10-03'; // Sabtu

    assert.equal(presetDate(today, 'today'), '2026-10-03');
    assert.equal(presetDate(today, '7'), '2026-10-10');
    assert.equal(presetDate(today, '14'), '2026-10-17');
    assert.equal(presetDate(today, '30'), '2026-11-02');
    assert.equal(presetDate(today, '45'), '2026-11-17');
    assert.equal(presetDate(today, '60'), '2026-12-02');
});

test('preset melewati akhir bulan dan akhir tahun dengan benar', () => {
    // 30 hari dari 31 Desember = 30 Januari tahun depan (tahun kabisati 2028).
    assert.equal(presetDate('2027-12-31', '30'), '2028-01-30');
    assert.equal(presetDate('2026-12-31', '30'), '2027-01-30');
    // 45 hari dari 20 Januari.
    assert.equal(presetDate('2026-01-20', '45'), '2026-03-06');
    // 7 hari dari 28 Februari 2028 (tahun kabisati).
    assert.equal(presetDate('2028-02-28', '7'), '2028-03-06');
});

test('preset "custom" dan kunci asing tidak menghasilkan tanggal', () => {
    assert.equal(presetDate('2026-10-03', 'custom'), null);
    assert.equal(presetDate('2026-10-03', 'bogus'), null);
    assert.equal(presetDate('2026-10-03', '10'), null); // 10 bukan preset yang didukung
});

test('daftar preset persis 6 tombol (tambah "custom" = 7 opsi)', () => {
    assert.deepEqual(PRESET_KEYS, ['today', '7', '14', '30', '45', '60']);
});

test('format tampilan DD/MM/YYYY sesuai format aplikasi', () => {
    assert.equal(toDisplay('2026-10-03'), '03/10/2026');
    assert.equal(toDisplay('2026-01-05'), '05/01/2026');
    assert.equal(toDisplay('2026-12-31'), '31/12/2026');
});

test('konversi tanggal bolak-balik stabil (tanpa pergeseran zona waktu)', () => {
    ['2026-10-03', '2026-01-01', '2026-12-31', '2028-02-29'].forEach((ymd) => {
        assert.equal(toYmd(toLocal(ymd)), ymd);
    });
});

test('jarak hari dihitung dalam hari kalender, boleh negatif', () => {
    assert.equal(daysBetween('2026-10-03', '2026-10-10'), 7);
    assert.equal(daysBetween('2026-10-03', '2026-10-03'), 0);
    assert.equal(daysBetween('2026-10-03', '2026-09-26'), -7);
    // Melewati akhir bulan: 30 hari dari 3 Oktober = 2 November.
    assert.equal(daysBetween('2026-10-03', '2026-11-02'), 30);
});

test('presetKeyFor mencocokkan tanggal hasil preset', () => {
    const today = '2026-10-03';

    PRESET_KEYS.forEach((key) => {
        assert.equal(presetKeyFor(today, presetDate(today, key)), key);
    });
});

test('presetKeyFor mengembalikan "custom" untuk tanggal bebas', () => {
    const today = '2026-10-03';

    assert.equal(presetKeyFor(today, '2026-10-05'), 'custom'); // +2 hari, bukan preset
    assert.equal(presetKeyFor(today, '2026-10-10'), '7');
    assert.equal(presetKeyFor(today, '2026-09-01'), 'custom'); // tanggal lalu
});

test('addDays negatif dan nol berperilaku wajar', () => {
    assert.equal(addDays('2026-10-03', 0), '2026-10-03');
    assert.equal(addDays('2026-10-03', -3), '2026-09-30');
    assert.equal(addDays('2026-03-01', -1), '2026-02-28');
});