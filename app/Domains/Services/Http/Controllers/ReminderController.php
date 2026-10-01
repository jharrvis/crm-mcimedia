<?php

namespace App\Domains\Services\Http\Controllers;

use App\Domains\Services\Models\Service;
use Illuminate\Http\Request;

class ReminderController
{
    public function __invoke(Request $request)
    {
        $days = 30;

        return view('reminders.index', [
            'days' => $days,
            'overdue' => Service::overdue()->with('client')->orderBy('end_date')->get(),
            'expiring' => Service::expiringSoon($days)->with('client')->orderBy('end_date')->get(),
        ]);
    }
}
