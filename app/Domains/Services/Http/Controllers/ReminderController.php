<?php

namespace App\Domains\Services\Http\Controllers;

use App\Domains\Clients\Models\Client;
use App\Domains\Services\Models\Service;
use Illuminate\Http\Request;

class ReminderController
{
    public function __invoke(Request $request)
    {
        $days = 30;
        // Filter klien (UX-4): nilai asing diabaikan agar tidak pernah masuk
        // ke klausa SQL; query dasar dipakai untuk kedua daftar.
        $clientId = $request->filled('client_id') && Client::query()->whereKey($request->integer('client_id'))->exists()
            ? $request->integer('client_id')
            : null;

        $base = fn () => Service::query()
            ->when($clientId, fn ($q) => $q->where('client_id', $clientId))
            ->with('client')
            ->orderBy('end_date');

        return view('reminders.index', [
            'days' => $days,
            'clients' => Client::orderBy('name')->get(['id', 'name', 'contact_name']),
            'overdue' => $base()->overdue()->get(),
            'expiring' => $base()->expiringSoon($days)->get(),
        ]);
    }
}
