{{-- Tabel bergaya template: header uppercase abu, baris hover, geser horizontal.
     Pakai sebagai: <x-table><x-slot:head>...</x-slot:head><x-slot:body>...</x-slot:body></x-table> --}}
<div {{ $attributes->merge(['class' => 'overflow-x-auto']) }}>
    <table class="w-full text-left text-sm">
        <thead class="border-y border-slate-100 bg-slate-50 text-xs uppercase tracking-wider text-slate-400 dark:border-slate-800 dark:bg-slate-800/50">
            {{ $head ?? '' }}
        </thead>
        <tbody>
            {{ $body ?? $slot }}
        </tbody>
    </table>
</div>