<?php

namespace App\Domains\Hestia\Http\Controllers;

use App\Domains\Clients\Models\Client;
use App\Domains\Hestia\Http\Requests\HestiaMapRequest;
use App\Domains\Hestia\Models\HestiaAccount;
use App\Domains\Hestia\Models\HestiaSyncLog;
use App\Domains\Hestia\Services\HestiaClient;
use App\Domains\Hestia\Services\HestiaSyncService;
use App\Http\Controllers\Controller;

class HestiaController extends Controller
{
    public function index(HestiaClient $client)
    {
        return view('hestia.index', [
            'accounts' => HestiaAccount::with(['client', 'service'])
                ->orderBy('domain')
                ->paginate(25)
                ->withQueryString(),
            'unmapped' => HestiaAccount::unmapped()->orderBy('domain')->get(),
            'logs' => HestiaSyncLog::latest()->limit(10)->get(),
            'clients' => Client::orderBy('name')->get(['id', 'name']),
            'configured' => (bool) config('crm.hestia.enabled', false) && $client->isConfigured(),
        ]);
    }

    public function sync(HestiaSyncService $sync)
    {
        $log = $sync->sync();

        if (! $log->isSuccess()) {
            return redirect()->route('hestia.index')
                ->with('error', 'Sinkronisasi gagal: '.($log->message ?? 'tidak diketahui'));
        }

        return redirect()->route('hestia.index')
            ->with('success', "Sinkronisasi selesai: {$log->pulled} akun ditarik, {$log->created} baru, {$log->updated} diperbarui, {$log->deactivated} dinonaktifkan.");
    }

    public function map(HestiaMapRequest $request, HestiaAccount $account, HestiaSyncService $sync)
    {
        $service = $sync->assignClient($account, $request->integer('client_id'));

        return redirect()->route('hestia.index')
            ->with('success', "Akun {$account->domain} dipetakan ke layanan #{$service->id}.");
    }

    public function ignore(HestiaAccount $account, HestiaSyncService $sync)
    {
        if ($account->service_id !== null) {
            return redirect()->route('hestia.index')
                ->with('error', "Akun {$account->domain} sudah terhubung ke sebuah layanan dan tidak bisa diabaikan.");
        }

        $sync->ignoreAccount($account);

        return redirect()->route('hestia.index')
            ->with('success', "Akun {$account->domain} diabaikan.");
    }
}
