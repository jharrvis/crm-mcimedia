<?php

namespace App\Domains\Services\Http\Controllers;

use App\Domains\Clients\Models\Client;
use App\Domains\Services\Enums\ServiceStatus;
use App\Domains\Services\Enums\ServiceType;
use App\Domains\Services\Models\Service;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

class DomainController extends Controller
{
    public function index(Request $request)
    {
        $query = Service::query()
            ->where('type', ServiceType::Domain)
            ->with(['client']);

        // Search nama domain
        if ($q = $request->get('q')) {
            $query->where(function ($w) use ($q) {
                $w->where('name', 'like', "%{$q}%")
                  ->orWhere('reference', 'like', "%{$q}%");
            });
        }

        // Filter klien
        if ($clientId = $request->get('client_id')) {
            $query->where('client_id', $clientId);
        }

        // Filter: belum ada klien (belum di-mapping)
        if ($request->get('unmapped') === '1') {
            $query->whereNull('client_id');
        }

        // Filter status
        $status = $request->get('status', 'all');
        if ($status !== 'all') {
            $query->where('status', $status);
        }

        // Sort
        $sort = $request->get('sort', 'end_date');
        $dir = $request->get('dir', 'asc');
        $allowedSorts = ['name', 'end_date', 'status'];
        if (in_array($sort, $allowedSorts)) {
            $query->orderBy($sort, $dir === 'desc' ? 'desc' : 'asc');
        }

        $domains = $query->paginate(25)->withQueryString();

        $clients = Client::orderBy('name')->get(['id', 'name']);
        $statuses = ServiceStatus::cases();

        // Hitung statistik
        $stats = [
            'total' => Service::where('type', ServiceType::Domain)->count(),
            'unmapped' => Service::where('type', ServiceType::Domain)->whereNull('client_id')->count(),
            'expiring' => Service::where('type', ServiceType::Domain)
                ->whereNotNull('end_date')
                ->whereDate('end_date', '<=', now()->addDays(30))
                ->whereDate('end_date', '>=', now())
                ->count(),
            'expired' => Service::where('type', ServiceType::Domain)
                ->whereNotNull('end_date')
                ->whereDate('end_date', '<', now())
                ->count(),
        ];

        return view('domains.index', compact('domains', 'clients', 'statuses', 'stats'));
    }
}
