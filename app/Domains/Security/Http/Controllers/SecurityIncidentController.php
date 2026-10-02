<?php

namespace App\Domains\Security\Http\Controllers;

use App\Domains\Clients\Models\Client;
use App\Domains\Security\Enums\IncidentSeverity;
use App\Domains\Security\Enums\IncidentSource;
use App\Domains\Security\Enums\IncidentStatus;
use App\Domains\Security\Http\Requests\SecurityIncidentRequest;
use App\Domains\Security\Models\SecurityIncident;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class SecurityIncidentController extends Controller
{
    public function index(Request $request): View
    {
        $incidents = SecurityIncident::with('client')
            ->when($request->filled('client_id'), fn ($q) => $q->where('client_id', $request->integer('client_id')))
            ->when($request->filled('severity') && $request->string('severity') !== 'all', fn ($q) => $q->where('severity', $request->string('severity')))
            ->when($request->filled('status') && $request->string('status') !== 'all', fn ($q) => $q->where('status', $request->string('status')))
            ->when($request->filled('q'), fn ($q) => $q->where('title', 'like', '%'.$request->string('q').'%'))
            ->orderByDesc('occurred_at')
            ->orderByDesc('id')
            ->paginate(25)
            ->withQueryString();

        return view('security.incidents.index', [
            'incidents' => $incidents,
            'clients' => Client::orderBy('name')->get(['id', 'name']),
            'severities' => IncidentSeverity::cases(),
            'statuses' => IncidentStatus::cases(),
        ]);
    }

    public function create(Request $request): View
    {
        return view('security.incidents.create', [
            'incident' => new SecurityIncident([
                'client_id' => $request->integer('client_id') ?: null,
                'occurred_at' => now(),
                'severity' => IncidentSeverity::Medium,
                'source' => IncidentSource::Manual,
                'status' => IncidentStatus::Open,
            ]),
            'clients' => Client::orderBy('name')->get(['id', 'name']),
            'severities' => IncidentSeverity::cases(),
            'sources' => IncidentSource::cases(),
            'statuses' => IncidentStatus::cases(),
        ]);
    }

    public function store(SecurityIncidentRequest $request): RedirectResponse
    {
        $data = $request->validated();
        $data['status'] = IncidentStatus::from($data['status']);
        $data['resolved_at'] = $data['status'] === IncidentStatus::Resolved ? now() : null;

        $incident = SecurityIncident::create($data);

        return redirect()->route('security.incidents.index')
            ->with('success', "Insiden \"{$incident->title}\" dicatat.");
    }

    public function edit(SecurityIncident $incident): View
    {
        return view('security.incidents.edit', [
            'incident' => $incident,
            'clients' => Client::orderBy('name')->get(['id', 'name']),
            'severities' => IncidentSeverity::cases(),
            'sources' => IncidentSource::cases(),
            'statuses' => IncidentStatus::cases(),
        ]);
    }

    public function update(SecurityIncidentRequest $request, SecurityIncident $incident): RedirectResponse
    {
        $data = $request->validated();
        $status = IncidentStatus::from($data['status']);

        $incident->update([
            ...$data,
            'status' => $status,
            'resolved_at' => $status === IncidentStatus::Resolved ? ($incident->resolved_at ?? now()) : null,
        ]);

        return redirect()->route('security.incidents.index')
            ->with('success', "Insiden \"{$incident->title}\" diperbarui.");
    }

    public function destroy(SecurityIncident $incident): RedirectResponse
    {
        $title = $incident->title;
        $incident->delete();

        return redirect()->route('security.incidents.index')
            ->with('success', "Insiden \"{$title}\" dihapus.");
    }
}
