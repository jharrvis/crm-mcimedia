<?php

namespace App\Domains\Core\Http\Controllers;

use App\Domains\Core\Models\ActivityLog;
use App\Http\Controllers\Controller;

class ActivityLogController extends Controller
{
    public function index()
    {
        $logs = ActivityLog::with('user')->latest()->paginate(25);

        return view('activity.index', compact('logs'));
    }
}
