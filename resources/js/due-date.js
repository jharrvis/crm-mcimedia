// Matematika tanggal untuk preset jatuh tempo invoice (UX-3).
// Satu sumber kebenaran untuk UI (resources/js/due-date-picker.js) dan test
// (tests/JS/due-date.test.mjs), supaya preset tidak pernah berbeda antara yang
// tampil di layar dan yang tersimpan ke DB.

/** Kunci preset besides 'custom' (custom = pilih bebas lewat kalender). */
export const PRESET_KEYS = ['today', '7', '14', '30', '45', '60'];

const pad = (n) => String(n).padStart(2, '0');

/** 'YYYY-MM-DD' -> Date lokal (bukan UTC) supaya "hari ini" sesuai zona pengguna. */
export function toLocal(ymd) {
    const [y, m, d] = String(ymd).split('-').map(Number);
    return new Date(y, m - 1, d);
}

/** Date -> 'YYYY-MM-DD'. */
export function toYmd(dt) {
    return `${dt.getFullYear()}-${pad(dt.getMonth() + 1)}-${pad(dt.getDate())}`;
}

/** 'YYYY-MM-DD' -> 'DD/MM/YYYY' (format yang dipakai seluruh aplikasi). */
export function toDisplay(ymd) {
    const dt = toLocal(ymd);
    return `${pad(dt.getDate())}/${pad(dt.getMonth() + 1)}/${dt.getFullYear()}`;
}

/** Tanggal 'YYYY-MM-DD' ditambah N hari kalender (melewati akhir bulan & tahun). */
export function addDays(ymd, n) {
    const dt = toLocal(ymd);
    dt.setDate(dt.getDate() + n);
    return toYmd(dt);
}

/**
 * Tanggal hasil preset: 'today' -> hari ini, '7'/'14'/'30'/'45'/'60' -> hari ini + N hari.
 * Kunci lain (termasuk 'custom') -> null supaya pemanggil memakai kalender.
 */
export function presetDate(todayYmd, presetKey) {
    if (presetKey === 'today') return todayYmd;
    if (!PRESET_KEYS.includes(presetKey)) return null;

    return addDays(todayYmd, parseInt(presetKey, 10));
}

/** Jumlah hari kalender dari $from ke $until (boleh negatif = tanggal lalu). */
export function daysBetween(fromYmd, untilYmd) {
    const ms = toLocal(untilYmd).getTime() - toLocal(fromYmd).getTime();
    return Math.round(ms / 86_400_000);
}

/**
 * Kunci preset yang cocok dengan jarak $fromYmd ke $ymd, atau 'custom' bila
 * tidak ada preset yang cocok (mis. tanggal bebas dari kalender).
 */
export function presetKeyFor(fromYmd, ymd) {
    const days = daysBetween(fromYmd, ymd);

    return days === 0 ? 'today' : (PRESET_KEYS.includes(String(days)) ? String(days) : 'custom');
}