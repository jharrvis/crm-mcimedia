<?php

namespace App\Domains\Security\Http\Controllers;

use App\Domains\Clients\Models\Client;
use App\Domains\Security\Http\Requests\SecurityActionRequest;
use App\Domains\Security\Models\SecurityAction;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class SecurityActionController extends Controller
{
    public function index(Request $request): View
    {
        $actions = SecurityAction::with('client')
            ->when($request->filled('client_id'), fn ($q) => $q->where('client_id', $request->integer('client_id')))
            ->orderByDesc('acted_at')
            ->orderByDesc('id')
            ->paginate(25)
            ->withQueryString();

        return view('security.actions.index', [
            'actions' => $actions,
            'clients' => Client::orderBy('name')->get(['id', 'name']),
        ]);
    }

    public function create(Request $request): View
    {
        return view('security.actions.create', [
            'action' => new SecurityAction([
                'client_id' => $request->integer('client_id') ?: null,
                'acted_at' => now()->toDateString(),
                'performed_by' => auth()->user()?->name,
            ]),
            'clients' => Client::orderBy('name')->get(['id', 'name']),
        ]);
    }

    public function store(SecurityActionRequest $request): RedirectResponse
    {
        SecurityAction::create($request->validated());

        return redirect()->route('security.actions.index')
            ->with('success', 'Tindakan keamanan dicatat.');
    }

    public function edit(SecurityAction $action): View
    {
        return view('security.actions.edit', [
            'action' => $action,
            'clients' => Client::orderBy('name')->get(['id', 'name']),
        ]);
    }

    public function update(SecurityActionRequest $request, SecurityAction $action): RedirectResponse
    {
        $action->update($request->validated());

        return redirect()->route('security.actions.index')
            ->with('success', 'Tindakan keamanan diperbarui.');
    }

    public function destroy(SecurityAction $action): RedirectResponse
    {
        $action->delete();

        return redirect()->route('security.actions.index')
            ->with('success', 'Tindakan keamanan dihapus.');
    }
}
