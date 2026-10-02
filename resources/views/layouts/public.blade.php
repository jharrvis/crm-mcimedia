<!DOCTYPE html>
<html lang="id" class="h-full">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>@yield('title', 'Invoice') — {{ config('crm.business.name') }}</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="min-h-full bg-slate-100 text-slate-800 dark:bg-slate-950 dark:text-slate-100">
    <div class="mx-auto max-w-3xl px-4 py-8">
        <div class="mb-6 flex items-center gap-3">
            @if (business_logo_url())
                <img src="{{ business_logo_url() }}" alt="{{ config('crm.business.name') }}"
                     class="h-9 w-auto max-w-[180px] object-contain">
            @else
                <div class="flex h-9 w-9 items-center justify-center rounded-lg bg-indigo-600 text-sm font-bold text-white">M</div>
            @endif
            <p class="text-lg font-bold">{{ config('crm.business.name') }}</p>
        </div>

        @if (session('success'))
            <div class="mb-4 rounded-lg border border-green-200 bg-green-50 px-4 py-3 text-sm text-green-800 dark:border-green-900 dark:bg-green-950/40 dark:text-green-200">
                {{ session('success') }}
            </div>
        @endif
        @if (session('error'))
            <div class="mb-4 rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-800 dark:border-red-900 dark:bg-red-950/40 dark:text-red-200">
                {{ session('error') }}
            </div>
        @endif

        @yield('content')

        <p class="mt-8 text-center text-xs text-slate-500">
            Halaman invoice resmi — {{ config('crm.business.name') }}
        </p>
    </div>
</body>
</html>
