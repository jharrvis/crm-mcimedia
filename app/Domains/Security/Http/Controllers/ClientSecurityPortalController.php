<?php

namespace App\Domains\Security\Http\Controllers;

use App\Domains\Clients\Models\Client;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Str;

/**
 * Kelola tautan laporan keamanan publik per klien (F3-3) dari sisi admin.
 */
class ClientSecurityPortalController extends Controller
{
    /** Buat (atau ganti) token → tautan sebelumnya otomatis tidak berlaku. */
    public function generate(Client $client): RedirectResponse
    {
        $client->forceFill(['security_portal_token' => Str::random(64)])->save();

        return back()->with('success', "Tautan laporan keamanan untuk {$client->name} dibuat.");
    }

    /** Cabut tautan (token dikosongkan) → halaman publik jadi 404. */
    public function revoke(Client $client): RedirectResponse
    {
        $client->forceFill(['security_portal_token' => null])->save();

        return back()->with('success', "Tautan laporan keamanan untuk {$client->name} dicabut.");
    }
}
