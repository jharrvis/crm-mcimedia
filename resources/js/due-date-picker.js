import { presetDate, toDisplay, toLocal, presetKeyFor } from './due-date';

// Glue DOM untuk date picker preset. Markup di
// resources/views/invoices/_due-date-picker.blade.php; semua hitung tanggal
// didelegasikan ke resources/js/due-date.js (diuji).

function initPicker(root) {
    if (root.dataset.ready === '1') return;
    root.dataset.ready = '1';

    const monthNames = JSON.parse(root.dataset.monthNames);
    const dayNames = JSON.parse(root.dataset.dayNames);
    const todayStr = root.dataset.today;
    const input = root.querySelector('[data-due-input]');
    const display = root.querySelector('[data-due-display]');
    const panel = root.querySelector('[data-due-panel]');
    const monthLabel = root.querySelector('[data-due-month-label]');
    const weekdays = root.querySelector('[data-due-weekdays]');
    const daysBox = root.querySelector('[data-due-days]');

    let selected = /^\d{4}-\d{2}-\d{2}$/.test(input.value) ? input.value : todayStr;
    let viewMonth = toLocal(selected);
    weekdays.innerHTML = dayNames.map((d) => `<div>${d}</div>`).join('');

    function paintPreset(presetKey) {
        root.querySelectorAll('[data-due-preset]').forEach((btn) => {
            const on = btn.dataset.duePreset === presetKey;
            btn.setAttribute('aria-selected', on ? 'true' : 'false');
            btn.className = 'flex w-full items-center gap-2 rounded px-3 py-2 text-left text-sm ' +
                (on ? 'bg-indigo-600 text-white' : 'text-slate-700 hover:bg-slate-100 dark:text-slate-300 dark:hover:bg-slate-800');
            const check = btn.querySelector('[data-due-check]');
            check.className = 'flex h-4 w-4 shrink-0 items-center justify-center rounded border ' +
                (on ? 'border-white bg-white' : 'border-slate-400 dark:border-slate-500');
            check.innerHTML = on
                ? '<svg viewBox="0 0 12 12" class="h-3 w-3 text-indigo-600" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M2 6.5 4.5 9 10 3.5" stroke-linecap="round" stroke-linejoin="round"/></svg>'
                : '';
        });
    }

    function setValue(ymd, presetKey) {
        input.value = ymd;
        display.value = toDisplay(ymd);
        selected = ymd;
        viewMonth = toLocal(ymd);
        paintPreset(presetKey);
        renderCalendar();
    }

    function openPanel() {
        panel.hidden = false;
        display.setAttribute('aria-expanded', 'true');
    }

    function closePanel(restoreFocus = false) {
        panel.hidden = true;
        display.setAttribute('aria-expanded', 'false');
        // Fokus harus balik ke input display, bukan hilang di elemen yang
        // baru saja disembunyikan.
        if (restoreFocus) display.focus();
    }

    function renderCalendar() {
        const year = viewMonth.getFullYear();
        const month = viewMonth.getMonth();
        monthLabel.textContent = `${monthNames[month]} ${year}`;

        const startOffset = (new Date(year, month, 1).getDay() + 6) % 7; // Senin = awal pekan
        const daysInMonth = new Date(year, month + 1, 0).getDate();

        let html = '';
        for (let i = 0; i < startOffset; i++) html += '<div></div>';
        for (let d = 1; d <= daysInMonth; d++) {
            const ymd = `${year}-${String(month + 1).padStart(2, '0')}-${String(d).padStart(2, '0')}`;
            const isToday = ymd === todayStr;
            const isSelected = ymd === selected;
            html += `<button type="button" data-due-day="${ymd}" aria-label="${toDisplay(ymd)}" class="aspect-square rounded-full text-xs transition ` +
                `${isSelected ? 'bg-indigo-600 font-semibold text-white' : 'text-slate-700 hover:bg-slate-100 dark:text-slate-300 dark:hover:bg-slate-800'} ` +
                `${isToday && !isSelected ? 'ring-1 ring-indigo-500' : ''}">${d}</button>`;
        }
        daysBox.innerHTML = html;
    }

    document.addEventListener('click', (e) => {
        if (!root.contains(e.target)) closePanel();
    });
    document.addEventListener('keydown', (e) => {
        if (e.key === 'Escape' && !panel.hidden) closePanel(true);
    });

    display.addEventListener('click', () => (panel.hidden ? openPanel() : closePanel()));
    display.addEventListener('keydown', (e) => {
        if (e.key === 'Enter' || e.key === ' ') {
            e.preventDefault();
            panel.hidden ? openPanel() : closePanel();
        }
    });

    root.addEventListener('click', (e) => {
        const presetBtn = e.target.closest('[data-due-preset]');
        if (presetBtn) {
            const key = presetBtn.dataset.duePreset;
            if (key === 'custom') return; // kalender sudah tampil di kanan
            setValue(presetDate(todayStr, key) ?? selected, key);
            closePanel(true);
            return;
        }

        const dayBtn = e.target.closest('[data-due-day]');
        if (dayBtn) {
            setValue(dayBtn.dataset.dueDay, 'custom');
            closePanel(true);
            return;
        }

        if (e.target.closest('[data-due-prev-month]')) {
            viewMonth = new Date(viewMonth.getFullYear(), viewMonth.getMonth() - 1, 1);
            renderCalendar();
        } else if (e.target.closest('[data-due-next-month]')) {
            viewMonth = new Date(viewMonth.getFullYear(), viewMonth.getMonth() + 1, 1);
            renderCalendar();
        }
    });

    setValue(selected, presetKeyFor(todayStr, selected));
}

export function initDueDatePickers() {
    document.querySelectorAll('.due-date-picker').forEach(initPicker);
}