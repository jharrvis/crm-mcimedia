<?php

namespace App\Domains\Quotation\Http\Controllers;

use App\Domains\Catalog\Models\Product;
use App\Domains\Catalog\Models\ProductCategory;
use App\Domains\Clients\Models\Client;
use App\Domains\Quotation\Enums\QuotationStatus;
use App\Domains\Quotation\Exceptions\InvalidQuotationTransition;
use App\Domains\Quotation\Http\Requests\QuotationRequest;
use App\Domains\Quotation\Models\Quotation;
use App\Domains\Quotation\Services\QuotationNumber;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

class QuotationController
{
    public function index(Request $request)
    {
        $quotations = Quotation::with('client')
            ->when($request->filled('q'), function ($query) use ($request) {
                $q = $request->string('q')->toString();
                $query->where(function ($w) use ($q) {
                    $w->where('number', 'like', "%{$q}%")
                        ->orWhere('title', 'like', "%{$q}%")
                        ->orWhereHas('client', fn ($c) => $c->where('name', 'like', "%{$q}%"));
                });
            })
            ->when($request->filled('client_id'), fn ($query) => $query->where('client_id', $request->integer('client_id')))
            ->when(
                $request->filled('status') && $request->string('status')->toString() !== 'all',
                fn ($query) => $query->where('status', $request->string('status')->toString())
            )
            ->orderByDesc('issue_date')
            ->orderByDesc('id')
            ->paginate(25)
            ->withQueryString();

        return view('quotations.index', [
            'quotations' => $quotations,
            'clients' => Client::orderBy('name')->get(['id', 'name']),
            'statuses' => QuotationStatus::cases(),
        ]);
    }

    public function create()
    {
        return view('quotations.create', [
            'previewNumber' => QuotationNumber::next(),
            'clients' => Client::orderBy('name')->get(['id', 'name']),
            // Bootstrap katalog untuk picker produk (mirror invoice UX-2).
            'products' => Product::active()->with(['variants' => fn ($v) => $v->active()->orderBy('sort_order')])
                ->get(['id', 'sku', 'name', 'sales_price', 'category_id']),
            'categories' => ProductCategory::orderBy('sort_order')->orderBy('name')->get(['id', 'name']),
        ]);
    }

    public function store(QuotationRequest $request)
    {
        $data = $request->validated();
        $items = $request->items();
        $saveAction = $request->saveAction();
        unset($data['items'], $data['save_action']);

        $quotation = DB::transaction(function () use ($data, $items) {
            $quotation = Quotation::create([
                ...$data,
                'number' => QuotationNumber::next(Carbon::parse($data['issue_date'])),
                'status' => QuotationStatus::Draft,
            ]);

            foreach ($items as $sort => $item) {
                $quotation->items()->create([
                    'description' => $item['description'],
                    'quantity' => $item['quantity'],
                    'unit_price' => $item['unit_price'],
                    'amount' => 0,
                    'sort_order' => $sort,
                ]);
            }

            $quotation->recalculateTotals();

            return $quotation;
        });

        if ($saveAction === 'confirm') {
            $quotation->markSent();

            return redirect()->route('quotations.show', $quotation)
                ->with('success', "Penawaran {$quotation->number} disimpan dan ditandai terkirim.");
        }

        if ($saveAction === 'send') {
            return redirect()->route('quotations.show', $quotation)
                ->with('success', "Penawaran {$quotation->number} disimpan sebagai draf. Tandai terkirim untuk mengirim ke klien.");
        }

        return redirect()->route('quotations.show', $quotation)
            ->with('success', "Penawaran {$quotation->number} berhasil dibuat.");
    }

    public function show(Quotation $quotation)
    {
        $quotation->load(['client', 'items']);

        return view('quotations.show', compact('quotation'));
    }

    public function edit(Quotation $quotation)
    {
        if ($quotation->isTerminal()) {
            return redirect()->route('quotations.show', $quotation)
                ->with('error', "Penawaran {$quotation->number} berstatus {$quotation->status->label()} sehingga tidak dapat diubah.");
        }

        $quotation->load(['items']);

        return view('quotations.edit', [
            'quotation' => $quotation,
            'clients' => Client::orderBy('name')->get(['id', 'name']),
            // Bootstrap katalog untuk picker produk (mirror invoice UX-2).
            'products' => Product::active()->with(['variants' => fn ($v) => $v->active()->orderBy('sort_order')])
                ->get(['id', 'sku', 'name', 'sales_price', 'category_id']),
            'categories' => ProductCategory::orderBy('sort_order')->orderBy('name')->get(['id', 'name']),
        ]);
    }

    public function update(QuotationRequest $request, Quotation $quotation)
    {
        if ($quotation->isTerminal()) {
            return redirect()->route('quotations.show', $quotation)
                ->with('error', "Penawaran {$quotation->number} berstatus {$quotation->status->label()} sehingga tidak dapat diubah.");
        }

        $data = $request->validated();
        $items = $request->items();
        unset($data['items'], $data['save_action']);

        DB::transaction(function () use ($quotation, $data, $items) {
            $quotation->update($data);
            $quotation->items()->delete();

            foreach ($items as $sort => $item) {
                $quotation->items()->create([
                    'description' => $item['description'],
                    'quantity' => $item['quantity'],
                    'unit_price' => $item['unit_price'],
                    'amount' => 0,
                    'sort_order' => $sort,
                ]);
            }

            $quotation->recalculateTotals();
        });

        return redirect()->route('quotations.show', $quotation)
            ->with('success', "Penawaran {$quotation->number} berhasil diperbarui.");
    }

    public function destroy(Quotation $quotation)
    {
        if ($quotation->status !== QuotationStatus::Draft) {
            return redirect()->route('quotations.show', $quotation)
                ->with('error', 'Hanya penawaran berstatus draf yang dapat dihapus.');
        }

        $number = $quotation->number;
        $quotation->delete();

        return redirect()->route('quotations.index')
            ->with('success', "Penawaran {$number} dihapus.");
    }

    /** Draft/sent -> terkirim (tandai terkirim ke klien). */
    public function send(Quotation $quotation)
    {
        try {
            $quotation->markSent();
        } catch (InvalidQuotationTransition $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', "Penawaran {$quotation->number} ditandai terkirim.");
    }

    /** Unduh PDF resmi penawaran. */
    public function pdf(Quotation $quotation): Response
    {
        $quotation->load(['client', 'items']);

        return Pdf::loadView('quotations.pdf', compact('quotation'))
            ->setPaper('a4', 'portrait')
            ->download("{$quotation->number}.pdf");
    }
}
