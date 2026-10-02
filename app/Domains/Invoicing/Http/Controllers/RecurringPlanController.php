<?php

namespace App\Domains\Invoicing\Http\Controllers;

use App\Domains\Catalog\Models\Product;
use App\Domains\Clients\Models\Client;
use App\Domains\Invoicing\Enums\RecurringCycle;
use App\Domains\Invoicing\Http\Requests\RecurringPlanRequest;
use App\Domains\Invoicing\Models\RecurringPlan;
use App\Domains\Invoicing\Services\RecurringInvoiceGenerator;
use App\Domains\Services\Models\Service;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class RecurringPlanController extends Controller
{
    public function index(Request $request)
    {
        // Siklus & status memakai nilai sentinel 'all' untuk "tanpa filter".
        // Perbandingan harus dilakukan atas nilai SKALAR, bukan objek Stringable
        // dari $request->string() — `Stringable !== 'all'` selalu true karena
        // beda tipe, sehingga cycle=all berakhir jadi where cycle = 'all'
        // (hasil kosong). Validasi juga menolak nilai asing: cycle=weekly tidak
        // boleh diam-diam jadi query sia-sia.
        $cycles = RecurringCycle::cases();
        $cycle = $request->input('cycle');
        $cycle = in_array($cycle, array_column($cycles, 'value'), true) ? $cycle : null;

        $status = $request->input('status', 'all');

        $plans = RecurringPlan::query()
            ->with('client')
            ->when($request->filled('q'), function ($query) use ($request) {
                $q = $request->string('q');
                $query->where(function ($w) use ($q) {
                    $w->where('title', 'like', "%{$q}%")
                        ->orWhereHas('client', fn ($c) => $c->where('name', 'like', "%{$q}%"));
                });
            })
            ->when($request->filled('client_id'), fn ($query) => $query->where('client_id', $request->integer('client_id')))
            ->when($cycle, fn ($query) => $query->where('cycle', $cycle))
            ->when(in_array($status, ['active', 'inactive'], true), fn ($query) => $query->where('active', $status === 'active'))
            ->orderByRaw('next_invoice_date asc')
            ->orderBy('id')
            ->paginate(15)
            ->withQueryString();

        return view('recurring-plans.index', [
            'plans' => $plans,
            'clients' => Client::orderBy('name')->get(['id', 'name']),
            'cycles' => $cycles,
        ]);
    }

    public function create()
    {
        return view('recurring-plans.create', [
            'clients' => Client::orderBy('name')->get(['id', 'name']),
            'services' => Service::orderBy('name')->get(['id', 'client_id', 'name', 'price']),
            'cycles' => RecurringCycle::cases(),
            'products' => Product::active()->get(['id', 'name', 'sales_price']),
        ]);
    }

    public function store(RecurringPlanRequest $request): RedirectResponse
    {
        $plan = DB::transaction(function () use ($request) {
            $data = $request->planData();
            $items = $request->itemRows();
            unset($data['items']);

            $plan = RecurringPlan::create($data);

            foreach ($items as $item) {
                $plan->items()->create($item);
            }

            return $plan;
        });

        return redirect()->route('recurring-plans.show', $plan)
            ->with('success', "Paket recurring \"{$plan->title}\" berhasil dibuat.");
    }

    public function show(RecurringPlan $recurringPlan)
    {
        $recurringPlan->load(['client', 'service', 'items']);

        return view('recurring-plans.show', [
            'plan' => $recurringPlan,
            'invoices' => $recurringPlan->invoices()->with('payments')->orderByDesc('issue_date')->get(),
        ]);
    }

    public function edit(RecurringPlan $recurringPlan)
    {
        return view('recurring-plans.edit', [
            'plan' => $recurringPlan->load('items'),
            'clients' => Client::orderBy('name')->get(['id', 'name']),
            'services' => Service::orderBy('name')->get(['id', 'client_id', 'name', 'price']),
            'cycles' => RecurringCycle::cases(),
            'products' => Product::active()->get(['id', 'name', 'sales_price']),
        ]);
    }

    public function update(RecurringPlanRequest $request, RecurringPlan $recurringPlan): RedirectResponse
    {
        DB::transaction(function () use ($request, $recurringPlan) {
            $data = $request->planData();
            $items = $request->itemRows();
            unset($data['items']);

            $recurringPlan->update($data);
            $recurringPlan->items()->delete();

            foreach ($items as $item) {
                $recurringPlan->items()->create($item);
            }
        });

        return redirect()->route('recurring-plans.show', $recurringPlan)
            ->with('success', "Paket recurring \"{$recurringPlan->title}\" berhasil diperbarui.");
    }

    public function destroy(RecurringPlan $recurringPlan): RedirectResponse
    {
        $title = $recurringPlan->title;

        // Invoice yang sudah terbit tetap disimpan (dokumen keuangan); hanya
        // tautan paketnya yang dilepas (ON DELETE SET NULL).
        $recurringPlan->delete();

        return redirect()->route('recurring-plans.index')
            ->with('success', "Paket recurring \"{$title}\" dihapus. Invoice yang sudah terbit tetap tersimpan.");
    }

    /** Aktif/nonaktif paket (tanpa menghapus invoice terbit). */
    public function toggle(RecurringPlan $recurringPlan): RedirectResponse
    {
        $recurringPlan->update(['active' => ! $recurringPlan->active]);

        return back()->with('success', sprintf(
            'Paket recurring "%s" kini %s.',
            $recurringPlan->title,
            $recurringPlan->active ? 'aktif' : 'nonaktif',
        ));
    }

    /** Terbitkan invoice periode berjalan sekarang (tombol "Tagih sekarang"). */
    public function generateNow(RecurringPlan $recurringPlan): RedirectResponse
    {
        // Paket nonaktif tidak ditagih meski form dikirim langsung — sama
        // dengan command harian yang hanya memproses paket aktif. Tombolnya
        // memang disembunyikan di UI, tapi aturan bisnis ditegakkan di server.
        if (! $recurringPlan->active) {
            return back()->with('error', 'Paket ini nonaktif — aktifkan dulu sebelum menagih.');
        }

        $invoice = app(RecurringInvoiceGenerator::class)->generateForPlan($recurringPlan);

        if ($invoice === null) {
            $start = $recurringPlan->nextPeriodStart();

            if ($start->isFuture()) {
                return back()->with('error', "Periode berikutnya dimulai pada {$start->translatedFormat('d M Y')} — belum bisa ditagih.");
            }

            if ($recurringPlan->items()->doesntExist()) {
                return back()->with('error', 'Paket ini belum punya item — isi dulu sebelum menagih.');
            }

            return back()->with('error', 'Invoice untuk periode ini sudah pernah dibuat.');
        }

        return redirect()->route('invoices.show', $invoice)
            ->with('success', "Invoice {$invoice->number} dibuat dari paket recurring.");
    }
}