<?php

namespace App\Domains\Invoicing\Http\Controllers;

use App\Domains\Catalog\Models\Product;
use App\Domains\Clients\Models\Client;
use App\Domains\Core\Models\ActivityLog;
use App\Domains\Invoicing\Enums\InvoiceStatus;
use App\Domains\Invoicing\Exceptions\InvalidInvoiceTransition;
use App\Domains\Invoicing\Http\Requests\InvoiceRequest;
use App\Domains\Invoicing\Jobs\SendInvoiceEmailJob;
use App\Domains\Invoicing\Jobs\SendInvoiceWhatsappJob;
use App\Domains\Invoicing\Models\Invoice;
use App\Domains\Invoicing\Models\Payment;
use App\Domains\Invoicing\Services\InvoiceDelivery;
use App\Domains\Invoicing\Services\InvoiceNumber;
use App\Domains\Services\Models\Service;
use App\Http\Controllers\Controller;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

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
            'services' => Service::orderBy('name')->get(['id', 'client_id', 'name', 'price']),
            'products' => Product::active()->get(['id', 'name', 'sales_price']),
        ]);
    }

    public function store(InvoiceRequest $request)
    {
        $data = $request->validated();
        $items = $data['items'];
        $serviceIds = $request->serviceIds();
        unset($data['items'], $data['service_ids']);

        $invoice = DB::transaction(function () use ($data, $items, $serviceIds) {
            $invoice = Invoice::create([
                ...$data,
                'number' => InvoiceNumber::next(Carbon::parse($data['issue_date'])),
                'status' => InvoiceStatus::Draft,
            ]);

            $invoice->syncServices($serviceIds);

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
        $invoice->load(['client', 'services', 'items', 'payments.confirmer', 'recurringPlan']);

        // Riwayat pengiriman (F2-5) dari activity log untuk invoice ini.
        $deliveries = ActivityLog::query()
            ->where('subject_type', $invoice->getMorphClass())
            ->where('subject_id', $invoice->getKey())
            ->whereIn('event', [InvoiceDelivery::EVENT_EMAIL, InvoiceDelivery::EVENT_WHATSAPP])
            ->latest('id')
            ->limit(10)
            ->get();

        return view('invoices.show', compact('invoice', 'deliveries'));
    }

    public function edit(Invoice $invoice)
    {
        if ($invoice->isTerminal()) {
            return redirect()->route('invoices.show', $invoice)
                ->with('error', "Invoice {$invoice->number} berstatus {$invoice->status->label()} sehingga tidak dapat diubah.");
        }

        $invoice->load(['services', 'items']);

        return view('invoices.edit', [
            'invoice' => $invoice,
            'clients' => Client::orderBy('name')->get(['id', 'name']),
            'services' => Service::orderBy('name')->get(['id', 'client_id', 'name', 'price']),
            'products' => Product::active()->get(['id', 'name', 'sales_price']),
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
        $serviceIds = $request->serviceIds();
        unset($data['items'], $data['service_ids']);

        DB::transaction(function () use ($invoice, $data, $items, $serviceIds) {
            $invoice->update($data);
            $invoice->syncServices($serviceIds);
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

    /** Antre pengiriman invoice via email ke klien (F2-5). */
    public function sendEmail(Invoice $invoice)
    {
        if ($invoice->isTerminal()) {
            return back()->with('error', "Invoice {$invoice->number} berstatus {$invoice->status->label()} sehingga tidak dapat dikirim.");
        }

        $email = $invoice->client?->email;

        if (blank($email)) {
            return back()->with('error', 'Klien belum punya alamat email sehingga invoice tidak dapat dikirim via email.');
        }

        SendInvoiceEmailJob::dispatch($invoice);

        return back()->with('success', "Pengiriman email invoice {$invoice->number} ke {$email} sedang diantre.");
    }

    /** Antre pengiriman invoice via WhatsApp Fonnte ke klien (F2-5). */
    public function sendWhatsapp(Invoice $invoice)
    {
        if ($invoice->isTerminal()) {
            return back()->with('error', "Invoice {$invoice->number} berstatus {$invoice->status->label()} sehingga tidak dapat dikirim.");
        }

        if (! config('crm.fonnte.enabled') || blank(config('crm.fonnte.token'))) {
            return back()->with('error', 'Pengiriman WhatsApp belum dikonfigurasi (atur FONNTE_ENABLED dan FONNTE_TOKEN).');
        }

        $target = InvoiceDelivery::normalizeWhatsapp($invoice->client?->whatsapp);

        if (blank($target)) {
            return back()->with('error', 'Klien belum punya nomor WhatsApp sehingga invoice tidak dapat dikirim via WhatsApp.');
        }

        SendInvoiceWhatsappJob::dispatch($invoice);

        return back()->with('success', "Pengiriman WhatsApp invoice {$invoice->number} ke {$target} sedang diantre.");
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
        $invoice->load(['client', 'services', 'items', 'payments']);

        return Pdf::loadView('invoices.pdf', compact('invoice'))
            ->setPaper('a4', 'portrait')
            ->download("{$invoice->number}.pdf");
    }

    // ---------- Tautan pembayaran publik (magic link) ----------

    /** Buat (atau ganti) tautan pembayaran publik untuk invoice non-draf. */
    public function generatePaymentLink(Invoice $invoice)
    {
        if ($invoice->status === InvoiceStatus::Draft) {
            return back()->with('error', 'Tandai invoice terkirim sebelum membuat tautan pembayaran.');
        }

        $invoice->update(['public_token' => Str::random(64)]);

        return back()->with('success', "Tautan pembayaran invoice {$invoice->number} dibuat.");
    }

    /** Cabut tautan pembayaran publik (token dikosongkan). */
    public function revokePaymentLink(Invoice $invoice)
    {
        if ($invoice->status === InvoiceStatus::Draft) {
            return back()->with('error', 'Invoice draf tidak memiliki tautan pembayaran.');
        }

        $invoice->update(['public_token' => null]);

        return back()->with('success', "Tautan pembayaran invoice {$invoice->number} dicabut.");
    }

    // ---------- Verifikasi konfirmasi transfer klien ----------

    /** Konfirmasi pembayaran pending -> confirmed + invoice lunas. */
    public function confirmPendingPayment(Invoice $invoice, Payment $payment)
    {
        abort_if($payment->invoice_id !== $invoice->id, 404);

        if (! $payment->isPending()) {
            return back()->with('error', 'Pembayaran ini sudah diproses.');
        }

        try {
            DB::transaction(function () use ($invoice, $payment) {
                $payment->update([
                    'status' => 'confirmed',
                    'confirmed_by' => auth()->id(),
                ]);

                $invoice->markPaid();
            });
        } catch (InvalidInvoiceTransition $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', "Pembayaran invoice {$invoice->number} dikonfirmasi dan invoice ditandai lunas.");
    }

    /** Tolak pembayaran pending -> status rejected (kolom varchar mendukung, tanpa ubah skema). */
    public function rejectPendingPayment(Invoice $invoice, Payment $payment)
    {
        abort_if($payment->invoice_id !== $invoice->id, 404);

        if (! $payment->isPending()) {
            return back()->with('error', 'Pembayaran ini sudah diproses.');
        }

        $payment->update(['status' => 'rejected']);

        ActivityLog::record(
            $payment,
            'updated',
            "Konfirmasi pembayaran invoice {$invoice->number} sebesar ".rupiah($payment->amount).' ditolak.'
        );

        return back()->with('success', "Pembayaran invoice {$invoice->number} ditolak.");
    }
}
