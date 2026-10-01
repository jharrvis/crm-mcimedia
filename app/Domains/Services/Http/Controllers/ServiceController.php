<?php

namespace App\Domains\Services\Http\Controllers;

use App\Domains\Clients\Models\Client;
use App\Domains\Services\Enums\ServiceCycle;
use App\Domains\Services\Enums\ServiceStatus;
use App\Domains\Services\Enums\ServiceType;
use App\Domains\Services\Http\Requests\ServiceRequest;
use App\Domains\Services\Models\Service;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

class ServiceController extends Controller
{
    public function index(Request $request)
    {
        $services = Service::with('client')
            ->when($request->filled('q'), function ($query) use ($request) {
                $q = $request->string('q');
                $query->where(function ($w) use ($q) {
                    $w->where('name', 'like', "%{$q}%")
                        ->orWhere('reference', 'like', "%{$q}%");
                });
            })
            ->when($request->filled('client_id'), fn ($query) => $query->where('client_id', $request->integer('client_id')))
            ->when($request->filled('type'), fn ($query) => $query->where('type', $request->string('type')))
            ->when($request->get('status', 'active') !== 'all', function ($query) use ($request) {
                $query->where('status', $request->get('status', 'active'));
            })
            ->orderBy('end_date')
            ->paginate(15)
            ->withQueryString();

        return view('services.index', [
            'services' => $services,
            'clients' => Client::orderBy('name')->get(['id', 'name']),
            'types' => ServiceType::cases(),
            'statuses' => ServiceStatus::cases(),
        ]);
    }

    public function create()
    {
        return view('services.create', [
            'clients' => Client::orderBy('name')->get(['id', 'name']),
            'types' => ServiceType::cases(),
            'cycles' => ServiceCycle::cases(),
            'statuses' => ServiceStatus::cases(),
        ]);
    }

    public function store(ServiceRequest $request)
    {
        $data = $request->validated();
        $data['reminder_enabled'] = $request->boolean('reminder_enabled');

        $service = Service::create($data);

        return redirect()->route('services.show', $service)
            ->with('success', 'Layanan berhasil ditambahkan.');
    }

    public function show(Service $service)
    {
        $service->load('client');

        return view('services.show', compact('service'));
    }

    public function edit(Service $service)
    {
        return view('services.edit', [
            'service' => $service,
            'clients' => Client::orderBy('name')->get(['id', 'name']),
            'types' => ServiceType::cases(),
            'cycles' => ServiceCycle::cases(),
            'statuses' => ServiceStatus::cases(),
        ]);
    }

    public function update(ServiceRequest $request, Service $service)
    {
        $data = $request->validated();
        $data['reminder_enabled'] = $request->boolean('reminder_enabled');

        $service->update($data);

        return redirect()->route('services.show', $service)
            ->with('success', 'Layanan berhasil diperbarui.');
    }

    public function destroy(Service $service)
    {
        $service->delete();

        return redirect()->route('services.index')
            ->with('success', 'Layanan dihapus.');
    }
}
