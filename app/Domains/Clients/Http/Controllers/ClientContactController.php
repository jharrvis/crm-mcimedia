<?php

namespace App\Domains\Clients\Http\Controllers;

use App\Domains\Clients\Http\Requests\ClientContactRequest;
use App\Domains\Clients\Models\Client;
use App\Domains\Clients\Models\ClientContact;
use App\Http\Controllers\Controller;

class ClientContactController extends Controller
{
    public function create(Client $client)
    {
        return view('clients.contacts.create', compact('client'));
    }

    public function store(ClientContactRequest $request, Client $client)
    {
        $client->contacts()->create($request->validated());

        return redirect()->route('clients.show', $client)
            ->with('success', 'Kontak berhasil ditambahkan.');
    }

    public function edit(Client $client, ClientContact $contact)
    {
        abort_if($contact->client_id !== $client->id, 404);

        return view('clients.contacts.edit', compact('client', 'contact'));
    }

    public function update(ClientContactRequest $request, Client $client, ClientContact $contact)
    {
        abort_if($contact->client_id !== $client->id, 404);

        $contact->update($request->validated());

        return redirect()->route('clients.show', $client)
            ->with('success', 'Kontak berhasil diperbarui.');
    }

    public function destroy(Client $client, ClientContact $contact)
    {
        abort_if($contact->client_id !== $client->id, 404);

        $contact->delete();

        return redirect()->route('clients.show', $client)
            ->with('success', 'Kontak dihapus.');
    }
}
