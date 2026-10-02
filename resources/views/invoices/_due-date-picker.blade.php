{{--
    Date picker cepat dengan panel preset (UX-3).
    Kiri: daftar preset; kanan: kalender bulan bahasa Indonesia.

    Markup saja — perilaku JS ada di resources/js/due-date-picker.js (satu sumber
    kebenaran dengan test tests/JS/due-date.test.mjs), diinit dari app.js.
    Dipakai untuk field jatuh tempo di form invoice (tambah/edit).
    Variabel:
      $name        nama input yang menyimpan Y-m-d (hidden input).
      $value       nilai awal (Y-m-d) — biasanya old('due_date', default).
      $label       label form (opsional).
      $required    true bila field wajib (default false).
      $inputClass  kelas Tailwind untuk input preview.
      $errorBag   error key validasi (opsional).
--}}
@php
    $name = $name ?? 'due_date';
    $value = old($name, $value ?? now()->addDays(14)->toDateString());
    $required = $required ?? false;
    $inputClass = $inputClass ?? 'w-full rounded-lg border border-slate-300 px-3 py-2 text-sm dark:border-slate-700 dark:bg-slate-800';
    $errorBag = $errorBag ?? $name;

    $presets = [
        'custom' => 'Kustom',
        'today' => 'Hari ini',
        '7' => '7 Hari Selanjutnya',
        '14' => '14 Hari Selanjutnya',
        '30' => '30 Hari Selanjutnya',
        '45' => '45 Hari Selanjutnya',
        '60' => '60 Hari Selanjutnya',
    ];

    // Nilai awal yang aman: old() bisa berisi apa pun saat validasi gagal.
    $validValue = is_string($value) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) ? $value : now()->toDateString();

    // Penanda preset aktif dihitung di server agar tampil konsisten walau JS lambat jalan.
    $selectedPreset = 'custom';
    $diff = (int) round(\Illuminate\Support\Carbon::today()->diffInDays(\Illuminate\Support\Carbon::parse($validValue), false));
    $selectedPreset = [0 => 'today', 7 => '7', 14 => '14', 30 => '30', 45 => '45', 60 => '60'][$diff] ?? 'custom';

    $displayValue = tgl_id($validValue);
@endphp

<div class="due-date-picker w-full" data-selected-preset="{{ $selectedPreset }}"
     data-month-names='{!! json_encode(['Januari','Februari','Maret','April','Mei','Juni','Juli','Agustus','September','Oktober','November','Desember'], JSON_UNESCAPED_UNICODE) !!}'
     data-day-names='{!! json_encode(['Sn','Sl','Rb','Km','Jm','Sb','Mg'], JSON_UNESCAPED_UNICODE) !!}'
     data-today="{{ now()->toDateString() }}">
    @isset($label)
        <label class="mb-1 block text-sm font-medium">{{ $label }}@if($required)<span class="text-red-600"> *</span>@endif</label>
    @endisset

    {{-- Input yang benar-benar disubmit (Y-m-d). Diubah JS lewat preset/kalender. Tanpa JS, nilai default server tetap terkirim. --}}
    <input type="hidden" name="{{ $name }}" value="{{ $validValue }}" data-due-input>

    {{-- Preview UI (DD/MM/YYYY), readonly; klik untuk buka/tutup panel. --}}
    <input type="text" readonly data-due-display value="{{ $displayValue }}"
           placeholder="DD/MM/YYYY" aria-haspopup="dialog" aria-expanded="false"
           class="{{ $inputClass }} cursor-pointer bg-white dark:bg-slate-800">
    @error($errorBag)<p class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror

    <div data-due-panel hidden
         class="mt-2 overflow-hidden rounded-lg border border-slate-200 bg-white shadow-lg dark:border-slate-700 dark:bg-slate-900">
        <div class="flex flex-col sm:flex-row">
            {{-- Panel preset (kiri) --}}
            <div class="w-full border-b border-slate-200 sm:w-48 sm:shrink-0 sm:border-b-0 sm:border-r dark:border-slate-700">
                <ul class="max-h-56 overflow-y-auto p-1 sm:max-h-none" role="listbox" aria-label="Preset jatuh tempo">
                    @foreach ($presets as $key => $presetLabel)
                        <li>
                            <button type="button" role="option" data-due-preset="{{ $key }}"
                                    aria-selected="{{ $selectedPreset === $key ? 'true' : 'false' }}"
                                    class="flex w-full items-center gap-2 rounded px-3 py-2 text-left text-sm {{ $selectedPreset === $key
                                        ? 'bg-indigo-600 text-white'
                                        : 'text-slate-700 hover:bg-slate-100 dark:text-slate-300 dark:hover:bg-slate-800' }}">
                                <span class="flex h-4 w-4 shrink-0 items-center justify-center rounded border {{ $selectedPreset === $key
                                    ? 'border-white bg-white'
                                    : 'border-slate-400 dark:border-slate-500' }}" data-due-check>
                                    @if ($selectedPreset === $key)
                                        <svg viewBox="0 0 12 12" class="h-3 w-3 text-indigo-600" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M2 6.5 4.5 9 10 3.5" stroke-linecap="round" stroke-linejoin="round"/></svg>
                                    @endif
                                </span>
                                <span>{{ $presetLabel }}</span>
                            </button>
                        </li>
                    @endforeach
                </ul>
            </div>

            {{-- Kalender bulan (kanan), selalu tampil seperti referensi. --}}
            <div class="w-full p-2 sm:w-64 sm:shrink-0" data-due-calendar>
                <div class="mb-2 flex items-center justify-between">
                    <button type="button" data-due-prev-month aria-label="Bulan sebelumnya"
                            class="rounded px-2 py-1 text-slate-500 hover:bg-slate-100 dark:hover:bg-slate-800">&lt;</button>
                    <p class="text-sm font-medium" data-due-month-label></p>
                    <button type="button" data-due-next-month aria-label="Bulan berikutnya"
                            class="rounded px-2 py-1 text-slate-500 hover:bg-slate-100 dark:hover:bg-slate-800">&gt;</button>
                </div>
                <div class="mb-1 grid grid-cols-7 gap-0.5 text-center text-[11px] font-semibold text-slate-500" data-due-weekdays aria-hidden="true"></div>
                <div class="grid grid-cols-7 gap-0.5" data-due-days role="grid"></div>
            </div>
        </div>
    </div>
</div>