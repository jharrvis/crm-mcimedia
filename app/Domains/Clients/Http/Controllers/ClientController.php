<?php

namespace App\Domains\Clients\Http\Controllers;

use App\Domains\Clients\Http\Requests\ClientRequest;
use App\Domains\Clients\Models\Client;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

class ClientController extends Controller
{
    public function index(Request $request)
    {
        $clients = Client::query()
            ->when($request->filled('q'), fn ($q) => $q->where(
                fn ($q) => $q->where('name', 'like', '%'.$request->q.'%')
                    ->orWhere('contact_name', 'like', '%'.$request->q.'%')
            ))
            ->latest()
            ->paginate(15)
            ->withQueryString();

        return view('clients.index', compact('clients'));
    }

    public function create()
    {
        return view('clients.create');
    }

    public function store(ClientRequest $request)
    {
        $client = Client::create($request->validated());

        return redirect()->route('clients.show', $client)
            ->with('success', 'Klien berhasil ditambahkan.');
    }

    public function show(Client $client)
    {
        $client->load(['contacts', 'services', 'projects', 'tasks']);

        return view('clients.show', compact('client'));
    }

    public function edit(Client $client)
    {
        return view('clients.edit', compact('client'));
    }

    public function update(ClientRequest $request, Client $client)
    {
        $client->update($request->validated());

        return redirect()->route('clients.show', $client)
            ->with('success', 'Klien berhasil diperbarui.');
    }

    public function destroy(Client $client)
    {
        $client->delete();

        return redirect()->route('clients.index')
            ->with('success', 'Klien berhasil dihapus.');
    }
}
