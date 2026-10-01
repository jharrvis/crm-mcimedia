<!DOCTYPE html>
<html lang="id" class="h-full">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Masuk — CRM MCI Media</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="flex h-full items-center justify-center bg-slate-100 px-4 dark:bg-slate-950">
    <div class="w-full max-w-sm">
        <div class="mb-6 flex items-center justify-center gap-2">
            <div class="flex h-10 w-10 items-center justify-center rounded-lg bg-indigo-600 font-bold text-white">M</div>
            <p class="text-xl font-bold dark:text-white">CRM MCI Media</p>
        </div>
        @yield('content')
        <p class="mt-6 text-center text-xs text-slate-500">Akses internal — MCI Media</p>
    </div>
</body>
</html>
