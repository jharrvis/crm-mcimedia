@props([
    'back' => null,
    'backLabel' => null,
    'title',
    'subtitle' => null,
    'icon' => null,
    'cta' => null,
])
{{-- Breadcrumb + judul + slot aksi, sesuai pattern header halaman template. --}}

<div {{ $attributes->merge(['class' => 'mb-5']) }}>
    @if ($back)
        <a href="{{ $back }}" class="mb-2 inline-flex items-center gap-1.5 text-xs font-semibold text-brand-600 hover:text-brand-700">
            <i data-lucide="arrow-left" class="h-3.5 w-3.5"></i>
            {{ $backLabel ?? 'Kembali' }}
        </a>
    @endif

    <div class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
        <div class="flex min-w-0 items-start gap-3">
            @if ($icon)
                <div class="grid h-11 w-11 shrink-0 place-items-center rounded-xl bg-brand-50 text-brand-600 dark:bg-brand-500/10">
                    <i data-lucide="{{ $icon }}" class="h-5 w-5 shrink-0"></i>
                </div>
            @endif
            <div class="min-w-0">
                <h1 class="text-xl font-bold text-slate-900 dark:text-white sm:text-2xl">{{ $title }}</h1>
                @if ($subtitle)
                    <p class="mt-0.5 text-sm text-slate-400">{{ $subtitle }}</p>
                @endif
                @if (!$subtitle)
                    {{ $belowTitle ?? '' }}
                @endif
            </div>
        </div>

        <div class="flex shrink-0 items-center gap-2">
            {{ $actions ?? $cta ?? $slot }}
        </div>
    </div>
</div>