@extends('layouts.app')

@section('title', 'Termin — '.$invoice->number)

@section('content')
@php
    $inputClass = 'w-full rounded-lg border border-slate-300 px-3 py-2 text-sm dark:border-slate-700 dark:bg-slate-800';

    // Percent & tanggal dari old() kalau submit gagal, boleh diedit ulang.
    // Default: tiga termin 30/30/40 — pola paling umum.
    $rows = old('terms');
    if (! is_array($rows) || $rows === []) {
        $rows = [
            ['percent' => 30, 'due_date' => $invoice->due_date->toDateString()],
            ['percent' => 30, 'due_date' => $invoice->due_date->copy()->addMonth()->toDateString()],
            ['percent' => 40, 'due_date' => $invoice->due_date->copy()->addMonths(2)->toDateString()],
        ];
    }
@endphp

<div class="max-w-4xl space-y-4">

    <div class="flex flex-wrap items-center gap-2">
        <a href="{{ route('invoices.show', $invoice) }}" class="rounded-lg border border-slate-300 px-4 py-2 text-sm hover:bg-slate-50 dark:border-slate-700 dark:hover:bg-slate-800">Kembali</a>
    </div>

    <div class="rounded-xl border border-slate-200 bg-white p-6 dark:border-slate-800 dark:bg-slate-900">
        <p class="text-xs uppercase text-slate-500">Pecah menjadi termin</p>
        <p class="text-xl font-bold">{{ $invoice->number }}</p>
        @if ($invoice->title)<p class="text-sm text-slate-500">{{ $invoice->title }}</p>@endif

        <dl class="mt-4 grid gap-x-6 gap-y-3 text-sm sm:grid-cols-2">
            <div><dt class="text-slate-500">Klien</dt><dd class="font-medium">{{ $invoice->client?->name ?? '—' }}</dd></div>
            <div><dt class="text-slate-500">Nilai kontrak</dt><dd class="font-medium tabular-nums">{{ rupiah($invoice->total) }}</dd></div>
            <div><dt class="text-slate-500">Tanggal terbit</dt><dd class="font-medium">{{ tgl_id($invoice->issue_date) }}</dd></div>
            <div><dt class="text-slate-500">Jatuh tempo kontrak</dt><dd class="font-medium">{{ tgl_id($invoice->due_date) }}</dd></div>
            @if ($invoice->services->isNotEmpty())
                <div class="sm:col-span-2">
                    <dt class="text-slate-500">Layanan ({{ $invoice->services->count() }})</dt>
                    <dd class="font-medium">{{ $invoice->services->pluck('name')->join(', ') }}</dd>
                </div>
            @endif
        </dl>

        <p class="mt-4 rounded-lg bg-slate-50 p-3 text-xs text-slate-600 dark:bg-slate-800 dark:text-slate-300">
            Setiap termin dibuat sebagai invoice tersendiri (nomor, jatuh tempo, dan statusnya sendiri)
            dan menunjuk balik ke invoice kontrak ini. Invoice kontrak tetap menjadi dokumen acuan
            dan tidak ikut dihitung sebagai piutang — piutangnya ada di tiap termin.
        </p>
    </div>

    <form method="POST" action="{{ route('invoices.termin.store', $invoice) }}"
          class="rounded-xl border border-slate-200 bg-white p-6 dark:border-slate-800 dark:bg-slate-900">
        @csrf

        <div class="mb-2 flex flex-wrap items-center justify-between gap-2">
            <h2 class="text-sm font-semibold">Rencana termin <span class="text-red-600">*</span></h2>
            <button type="button" id="add-term"
                    class="rounded-lg border border-brand-300 px-3 py-1.5 text-xs font-semibold text-brand-600 hover:bg-brand-50 dark:border-brand-700 dark:hover:bg-brand-950">
                + Tambah termin
            </button>
        </div>

        @error('terms')<p class="mb-2 text-xs text-red-600">{{ $message }}</p>@enderror
        @php
            $termErrors = collect($errors->messages())
                ->filter(fn ($messages, $key) => str_starts_with($key, 'terms.'))
                ->flatten()
                ->unique()
                ->all();
        @endphp
        @foreach ($termErrors as $termError)
            <p class="mb-2 text-xs text-red-600">{{ $termError }}</p>
        @endforeach

        <div class="overflow-x-auto rounded-lg border border-slate-200 dark:border-slate-700">
            <table class="w-full text-sm" id="terms-table">
                <thead>
                    <tr class="bg-slate-50 text-left text-xs uppercase text-slate-500 dark:bg-slate-800">
                        <th class="w-12 px-3 py-2">#</th>
                        <th class="px-3 py-2">Persentase</th>
                        <th class="px-3 py-2">Nominal (IDR)</th>
                        <th class="px-3 py-2">Jatuh tempo</th>
                        <th class="w-12 px-3 py-2"></th>
                    </tr>
                </thead>
                <tbody id="terms-body">
                    @foreach ($rows as $row)
                        <tr class="term-row border-t border-slate-100 dark:border-slate-800">
                            <td class="px-3 py-2 text-slate-400" data-role="ordinal">{{ $loop->iteration }}</td>
                            <td class="px-3 py-2">
                                <input name="terms[{{ $loop->index }}][percent]" type="number" min="0.01" max="100" step="0.01"
                                       required value="{{ $row['percent'] ?? '' }}" class="{{ $inputClass }}" data-role="percent">
                            </td>
                            <td class="px-3 py-2 text-right font-medium tabular-nums" data-role="amount">—</td>
                            <td class="px-3 py-2">
                                <input name="terms[{{ $loop->index }}][due_date]" type="date" required
                                       value="{{ $row['due_date'] ?? '' }}" class="{{ $inputClass }}" data-role="due_date">
                            </td>
                            <td class="px-3 py-2 text-center">
                                <button type="button" class="text-red-500 hover:text-red-700" data-role="remove" title="Hapus baris">✕</button>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
                <tfoot>
                    <tr class="border-t border-slate-200 bg-slate-50 dark:border-slate-700 dark:bg-slate-800">
                        <td colspan="2" class="px-3 py-2 text-right text-xs font-semibold uppercase text-slate-500">Total</td>
                        <td class="px-3 py-2 text-right font-bold tabular-nums" id="terms-total">—</td>
                        <td colspan="2" class="px-3 py-2 text-right text-xs font-semibold" id="terms-percent">0%</td>
                    </tr>
                </tfoot>
            </table>
        </div>

        <p class="mt-2 text-xs text-slate-500">
            Total persentase harus tepat 100%. Sisa pembulatan rupiah otomatis
            diberikan ke termin dengan pecahan terbesar supaya jumlah nominal
            selalu sama dengan nilai kontrak.
        </p>

        <div class="mt-4 flex gap-2">
            <button class="rounded-lg bg-brand-600 px-4 py-2 text-sm font-semibold text-white hover:bg-brand-700">
                Buat {{ count($rows) }} termin
            </button>
            <a href="{{ route('invoices.show', $invoice) }}" class="rounded-lg border border-slate-300 px-4 py-2 text-sm hover:bg-slate-50 dark:border-slate-700 dark:hover:bg-slate-800">Batal</a>
        </div>
    </form>
</div>

<template id="term-row-template">
    <tr class="term-row border-t border-slate-100 dark:border-slate-800">
        <td class="px-3 py-2 text-slate-400" data-role="ordinal">1</td>
        <td class="px-3 py-2">
            <input name="__NAME__[percent]" type="number" min="0.01" max="100" step="0.01" required class="{{ $inputClass }}" data-role="percent">
        </td>
        <td class="px-3 py-2 text-right font-medium tabular-nums" data-role="amount">—</td>
        <td class="px-3 py-2">
            <input name="__NAME__[due_date]" type="date" required class="{{ $inputClass }}" data-role="due_date">
        </td>
        <td class="px-3 py-2 text-center">
            <button type="button" class="text-red-500 hover:text-red-700" data-role="remove" title="Hapus baris">✕</button>
        </td>
    </tr>
</template>

<script>
    (() => {
        const body = document.getElementById('terms-body');
        const template = document.getElementById('term-row-template');
        const totalEl = document.getElementById('terms-total');
        const percentEl = document.getElementById('terms-percent');
        const contractTotal = {{ (int) $invoice->total }};

        const fmt = (n) => 'Rp ' + n.toLocaleString('id-ID');

        function rows() {
            return Array.from(body.querySelectorAll('tr.term-row'));
        }

        function reindex() {
            rows().forEach((row, i) => {
                row.querySelectorAll('input[name]').forEach((input) => {
                    input.name = input.name.replace(/terms\[\d+\]/, `terms[${i}]`);
                });
                row.querySelector('[data-role=ordinal]').textContent = i + 1;
            });

            recalc();
        }

        // Pratinjau nominal memakai largest-remainder yang sama dengan server
        // supaya angka yang dilihat admin sama dengan yang tersimpan.
        function recalc() {
            let sum = 0;
            let allocated = 0;
            const entries = [];

            rows().forEach((row) => {
                const pct = parseFloat(row.querySelector('[data-role=percent]').value);
                if (!isFinite(pct) || pct <= 0) {
                    row.querySelector('[data-role=amount]').textContent = '—';
                    return;
                }

                sum += pct;
                const exact = contractTotal * pct / 100;
                const floored = Math.floor(exact);
                allocated += floored;
                entries.push({ row, floored, fraction: exact - floored });
            });

            // Sisa rupiah akibat pembulatan diberikan ke termin dengan pecahan
            // desimal terbesar — identik dengan largest-remainder di server.
            const remainder = contractTotal - allocated;
            const bonus = new Set(
                entries
                    .slice()
                    .sort((a, b) => b.fraction - a.fraction)
                    .slice(0, Math.max(0, remainder))
            );

            entries.forEach((entry) => {
                entry.row.querySelector('[data-role=amount]').textContent =
                    fmt(entry.floored + (bonus.has(entry) ? 1 : 0));
            });

            totalEl.textContent = fmt(contractTotal);
            const rounded = Math.round(sum * 100) / 100;
            percentEl.textContent = rounded + '%';
            percentEl.classList.toggle('text-red-600', Math.abs(rounded - 100) > 0.0001);
            percentEl.classList.toggle('text-emerald-600', Math.abs(rounded - 100) <= 0.0001);
        }

        document.getElementById('add-term').addEventListener('click', () => {
            const row = template.content.firstElementChild.cloneNode(true);
            row.querySelectorAll('input[name]').forEach((input) => {
                input.name = input.name.replace('__NAME__', `terms[${rows().length}]`);
            });
            body.appendChild(row);
            reindex();
            row.querySelector('[data-role=percent]').focus();
        });

        body.addEventListener('click', (e) => {
            const btn = e.target.closest('[data-role=remove]');
            if (!btn) return;

            // Sisakan minimal satu baris supaya form tidak pernah kosong.
            if (rows().length <= 1) return;

            btn.closest('tr.term-row').remove();
            reindex();
        });

        body.addEventListener('input', recalc);

        reindex();
        recalc();
    })();
</script>
@endsection