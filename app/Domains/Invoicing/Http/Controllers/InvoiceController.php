<?php

namespace App\Domains\Invoicing\Http\Controllers;

use App\Domains\Clients\Models\Client;
use App\Domains\Invoicing\Enums\InvoiceStatus;
use App\Domains\Invoicing\Exceptions\InvalidInvoiceTransition;
use App\Domains\Invoicing\Http\Requests\InvoiceRequest;
use App\Domains\Invoicing\Models\Invoice;
use App\Domains\Invoicing\Services\InvoiceNumber;
use App\Domains\Services\Models\Service;
use App\Http\Controllers\Controller;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class InvoiceController extends Controller
{
    public function index(Request $request)
    {
        $invoices = Invoice::with('client')
            ->when($request->filled('q'), function ($query) use ($request) {
                $q = $request->string('q');
                $query->where(function ($w) use ($q) {
                    $w->where('number', 'like', "%{$q}%")
                        ->orWhere('title', 'like', "%{$q}%")
                        ->orWhereHas('client', fn ($c) => $c->where('name', 'like', "%{$q}%"));
                });
            })
            ->when($request->filled('client_id'), fn ($query) => $query->where('client_id', $request->integer('client_id')))
            ->when($request->filled('status') && $request->string('status') !== 'all', fn ($query) => $query->where('status', $request->string('status')))
            ->orderByDesc('issue_date')
            ->orderByDesc('id')
            ->paginate(25)
            ->withQueryString();

        return view('invoices.index', [
            'invoices' => $invoices,
            'clients' => Client::orderBy('name')->get(['id', 'name']),
            'statuses' => InvoiceStatus::cases(),
        ]);
    }

    public function create()
    {
        return view('invoices.create', [
            'clients' => Client::orderBy('name')->get(['id', 'name']),
            'services' => Service::orderBy('name')->get(['id', 'client_id', 'name']),
        ]);
    }

    public function store(InvoiceRequest $request)
    {
        $data = $request->validated();
        $items = $data['items'];
        unset($data['items']);

        $invoice = DB::transaction(function () use ($data, $items) {
            $invoice = Invoice::create([
                ...$data,
                'number' => InvoiceNumber::next(Carbon::parse($data['issue_date'])),
                'status' => InvoiceStatus::Draft,
            ]);

            foreach (array_values($items) as $sort => $item) {
                $invoice->items()->create([
                    'description' => $item['description'],
                    'quantity' => (int) $item['quantity'],
                    'unit_price' => (int) $item['unit_price'],
                    'amount' => 0,
                    'sort_order' => $sort,
                ]);
            }

            $invoice->recalculateTotals();

            return $invoice;
        });

        return redirect()->route('invoices.show', $invoice)
            ->with('success', "Invoice {$invoice->number} berhasil dibuat.");
    }

    public function show(Invoice $invoice)
    {
        $invoice->load(['client', 'service', 'items', 'payments.confirmer']);

        return view('invoices.show', compact('invoice'));
    }

    public function edit(Invoice $invoice)
    {
        if ($invoice->isTerminal()) {
            return redirect()->route('invoices.show', $invoice)
                ->with('error', "Invoice {$invoice->number} berstatus {$invoice->status->label()} sehingga tidak dapat diubah.");
        }

        $invoice->load('items');

        return view('invoices.edit', [
            'invoice' => $invoice,
            'clients' => Client::orderBy('name')->get(['id', 'name']),
            'services' => Service::orderBy('name')->get(['id', 'client_id', 'name']),
        ]);
    }

    public function update(InvoiceRequest $request, Invoice $invoice)
    {
        if ($invoice->isTerminal()) {
            return redirect()->route('invoices.show', $invoice)
                ->with('error', "Invoice {$invoice->number} berstatus {$invoice->status->label()} sehingga tidak dapat diubah.");
        }

        $data = $request->validated();
        $items = $data['items'];
        unset($data['items']);

        DB::transaction(function () use ($invoice, $data, $items) {
            $invoice->update($data);
            $invoice->items()->delete();

            foreach (array_values($items) as $sort => $item) {
                $invoice->items()->create([
                    'description' => $item['description'],
                    'quantity' => (int) $item['quantity'],
                    'unit_price' => (int) $item['unit_price'],
                    'amount' => 0,
                    'sort_order' => $sort,
                ]);
            }

            $invoice->recalculateTotals();
        });

        return redirect()->route('invoices.show', $invoice)
            ->with('success', "Invoice {$invoice->number} berhasil diperbarui.");
    }

    public function destroy(Invoice $invoice)
    {
        if ($invoice->status !== InvoiceStatus::Draft) {
            return redirect()->route('invoices.show', $invoice)
                ->with('error', 'Hanya invoice berstatus draf yang dapat dihapus.');
        }

        $number = $invoice->number;
        $invoice->delete();

        return redirect()->route('invoices.index')
            ->with('success', "Invoice {$number} dihapus.");
    }

    /** Draft -> terkirim. */
    public function send(Invoice $invoice)
    {
        try {
            $invoice->markSent();
        } catch (InvalidInvoiceTransition $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', "Invoice {$invoice->number} ditandai terkirim.");
    }

    /** Batalkan invoice (draft/sent/overdue -> cancelled). */
    public function cancel(Invoice $invoice)
    {
        try {
            $invoice->cancel();
        } catch (InvalidInvoiceTransition $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', "Invoice {$invoice->number} dibatalkan.");
    }

    /** Catat pembayaran: buat payment confirmed + markPaid(). */
    public function recordPayment(Request $request, Invoice $invoice)
    {
        $validated = $request->validate([
            'amount' => ['required', 'integer', 'min:1'],
            'method' => ['required', 'string', 'in:bank_transfer,cash,qris,ewallet,other'],
            'paid_at' => ['required', 'date'],
            'note' => ['nullable', 'string', 'max:255'],
        ], [
            'amount.integer' => 'Nominal pembayaran harus bilangan bulat (IDR).',
        ]);

        try {
            DB::transaction(function () use ($invoice, $validated) {
                $invoice->payments()->create([
                    'amount' => (int) $validated['amount'],
                    'method' => $validated['method'],
                    'status' => 'confirmed',
                    'paid_at' => $validated['paid_at'],
                    'confirmed_by' => auth()->id(),
                    'note' => $validated['note'] ?? null,
                ]);

                $invoice->markPaid();
            });
        } catch (InvalidInvoiceTransition $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', "Pembayaran invoice {$invoice->number} dicatat.");
    }

    /** Unduh PDF resmi invoice. */
    public function pdf(Invoice $invoice)
    {
        $invoice->load(['client', 'service', 'items', 'payments']);

        return Pdf::loadView('invoices.pdf', compact('invoice'))
            ->setPaper('a4', 'portrait')
            ->download("{$invoice->number}.pdf");
    }
}
