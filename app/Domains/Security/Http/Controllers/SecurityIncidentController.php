<?php

namespace App\Domains\Security\Http\Controllers;

use App\Domains\Clients\Models\Client;
use App\Domains\Security\Enums\IncidentSeverity;
use App\Domains\Security\Enums\IncidentSource;
use App\Domains\Security\Enums\IncidentStatus;
use App\Domains\Security\Http\Requests\SecurityIncidentRequest;
use App\Domains\Security\Models\SecurityIncident;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class SecurityIncidentController extends Controller
{
    public function index(Request $request): View
    {
        $severity = $request->string('severity')->value();
        $status = $request->string('status')->value();
        $search = $request->string('q')->value();
        $clientId = $request->integer('client_id');

        $incidents = SecurityIncident::with('client')
            ->when($clientId > 0, fn ($query) => $query->where('client_id', $clientId))
            ->when($severity !== '' && $severity !== 'all', fn ($query) => $query->where('severity', $severity))
            ->when($status !== '' && $status !== 'all', fn ($query) => $query->where('status', $status))
            ->when($search !== '', fn ($query) => $query->where('title', 'like', '%'.$search.'%'))
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

    /**
     * JSON endpoint for realtime incident list polling.
     * Returns the same filtered data as index() but as JSON.
     */
    public function api(Request $request): JsonResponse
    {
        $severity = $request->string('severity')->value();
        $status = $request->string('status')->value();
        $search = $request->string('q')->value();
        $clientId = $request->integer('client_id');

        $incidents = SecurityIncident::with('client')
            ->when($clientId > 0, fn ($query) => $query->where('client_id', $clientId))
            ->when($severity !== '' && $severity !== 'all', fn ($query) => $query->where('severity', $severity))
            ->when($status !== '' && $status !== 'all', fn ($query) => $query->where('status', $status))
            ->when($search !== '', fn ($query) => $query->where('title', 'like', '%'.$search.'%'))
            ->orderByDesc('occurred_at')
            ->orderByDesc('id')
            ->paginate(25)
            ->withQueryString();

        $data = $incidents->items()->map(function ($incident) {
            return [
                'id' => $incident->id,
                'occurred_at' => $incident->occurred_at?->toIso8601String(),
                'client' => $incident->client ? ['id' => $incident->client->id, 'name' => $incident->client->name] : null,
                'severity' => [
                    'value' => $incident->severity->value,
                    'label' => $incident->severity->label(),
                    'badgeClass' => $incident->severity->badgeClass(),
                ],
                'source' => [
                    'value' => $incident->source->value,
                    'label' => $incident->source->label(),
                ],
                'title' => $incident->title,
                'description' => $incident->description,
                'status' => [
                    'value' => $incident->status->value,
                    'label' => $incident->status->label(),
                    'badgeClass' => $incident->status->badgeClass(),
                ],
                'edit_url' => route('security.incidents.edit', $incident),
                'destroy_url' => route('security.incidents.destroy', $incident),
            ];
        });

        return response()->json([
            'data' => $data,
            'current_page' => $incidents->currentPage(),
            'last_page' => $incidents->lastPage(),
            'total' => $incidents->total(),
            'per_page' => $incidents->perPage(),
            'filters' => [
                'client_id' => $clientId > 0 ? $clientId : null,
                'severity' => $severity !== '' ? $severity : 'all',
                'status' => $status !== '' ? $status : 'all',
                'q' => $search,
            ],
        ]);
    }
}
