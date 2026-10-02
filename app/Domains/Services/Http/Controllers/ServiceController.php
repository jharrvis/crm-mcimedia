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
        $services = Service::with(['client', 'parent'])
            // Subdomain ditampilkan tepat di bawah domain induknya (F4-9).
            ->groupedByParent()
            ->filtered(
                q: $request->filled('q') ? $request->string('q')->toString() : null,
                clientId: $request->filled('client_id') ? $request->integer('client_id') : null,
                type: $request->filled('type') ? $request->string('type')->toString() : null,
                status: $request->get('status', 'active'),
            )
            ->paginate(15)
            ->withQueryString();

        return view('services.index', [
            'services' => $services,
            'clients' => Client::orderBy('name')->get(['id', 'name']),
            'types' => ServiceType::cases(),
            'statuses' => ServiceStatus::cases(),
        ]);
    }

    public function create(Request $request)
    {
        return view('services.create', [
            'clients' => Client::orderBy('name')->get(['id', 'name']),
            'types' => ServiceType::cases(),
            'cycles' => ServiceCycle::cases(),
            'statuses' => ServiceStatus::cases(),
            'parentCandidates' => $this->parentCandidates($request),
            'suggestedParentId' => Service::suggestParentId(
                $request->input('name'),
                $request->input('reference'),
                $request->integer('client_id') ?: null,
            ),
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
        $service->load(['client', 'parent', 'children']);

        return view('services.show', compact('service'));
    }

    public function edit(Service $service, Request $request)
    {
        return view('services.edit', [
            'service' => $service,
            'clients' => Client::orderBy('name')->get(['id', 'name']),
            'types' => ServiceType::cases(),
            'cycles' => ServiceCycle::cases(),
            'statuses' => ServiceStatus::cases(),
            'parentCandidates' => $this->parentCandidates($request, $service),
            'suggestedParentId' => null,
        ]);
    }

    public function update(ServiceRequest $request, Service $service)
    {
        $data = $request->validated();
        $data['reminder_enabled'] = $request->boolean('reminder_enabled');

        // Subdomain anak selalu mengikuti klien domain induknya; kalau induknya
        // dipindah klien, anak-anaknya ikut dipindah agar tidak yatim di klien lama.
        $clientChanged = (int) $data['client_id'] !== (int) $service->client_id;

        $service->update($data);

        if ($clientChanged) {
            $service->children()->update(['client_id' => $service->client_id]);
        }

        return redirect()->route('services.show', $service)
            ->with('success', 'Layanan berhasil diperbarui.');
    }

    public function destroy(Service $service)
    {
        $childCount = $service->children()->count();

        // Subdomain anak dilepas (parent_id -> NULL oleh FK nullOnDelete), bukan
        // ikut terhapus; beri tahu admin berapa yang tersisa.
        $service->delete();

        $message = 'Layanan dihapus.';

        if ($childCount > 0) {
            $message .= " {$childCount} subdomain tetap disimpan sebagai layanan mandiri.";
        }

        return redirect()->route('services.index')
            ->with('success', $message);
    }

    /**
     * Kandidat domain induk untuk dropdown form (F4-9): layanan domain di
     * level atas, dibatasi ke klien terpilih bila ada.
     */
    private function parentCandidates(Request $request, ?Service $service = null)
    {
        $clientId = $request->integer('client_id') ?: $service?->client_id;

        return Service::query()
            ->when($service, fn ($query) => $query->whereKeyNot($service->getKey()))
            ->parentCandidates(clientId: $clientId ?: null)
            ->get(['id', 'client_id', 'name', 'reference']);
    }
}
