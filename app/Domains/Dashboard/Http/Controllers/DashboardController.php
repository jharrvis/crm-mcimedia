<?php

namespace App\Domains\Dashboard\Http\Controllers;

use App\Domains\Clients\Models\Client;
use App\Domains\Projects\Enums\ProjectStatus;
use App\Domains\Projects\Models\Project;
use App\Domains\Services\Models\Service;
use App\Domains\Tasks\Models\Task;
use Illuminate\Http\Request;

class DashboardController
{
    public function __invoke(Request $request)
    {
        return view('dashboard.index', [
            'stats' => [
                'clients' => Client::active()->count(),
                'services' => Service::active()->count(),
                'projects' => Project::running()->count(),
                'tasks' => Task::open()->count(),
            ],
            'expiringServices' => Service::expiringSoon(30)->with('client')->orderBy('end_date')->limit(10)->get(),
            'overdueServices' => Service::overdue()->with('client')->orderBy('end_date')->limit(10)->get(),
            'runningProjects' => Project::running()->with('client')->orderBy('deadline')->limit(8)->get(),
            'urgentTasks' => Task::urgent()->with(['client', 'project'])->orderBy('due_date')->limit(10)->get(),
            'statuses' => ProjectStatus::cases(),
        ]);
    }
}
