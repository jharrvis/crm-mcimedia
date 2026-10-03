<?php

namespace App\Domains\Invoicing\Http\Controllers;

use App\Domains\Catalog\Models\Product;
use App\Domains\Catalog\Models\ProductCategory;
use App\Domains\Clients\Models\Client;
use App\Domains\Core\Models\ActivityLog;
use App\Domains\Invoicing\Enums\InvoiceStatus;
use App\Domains\Invoicing\Exceptions\InvalidInvoiceTransition;
use App\Domains\Invoicing\Exceptions\InvalidTerminSplit;
use App\Domains\Invoicing\Http\Requests\InvoiceRequest;
use App\Domains\Invoicing\Http\Requests\InvoiceTerminRequest;
use App\Domains\Invoicing\Jobs\SendInvoiceEmailJob;
use App\Domains\Invoicing\Jobs\SendInvoiceWhatsappJob;
use App\Domains\Invoicing\Models\Invoice;
use App\Domains\Invoicing\Models\Payment;
use App\Domains\Invoicing\Services\InvoiceDelivery;
use App\Domains\Invoicing\Services\InvoiceNumber;
use App\Domains\Invoicing\Services\InvoiceTerminSplitter;
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
            // Bootstrap katalog untuk picker UX-2 (server-rendered agar tes
            // lama tetap terbaca): produk aktif beserta varian aktifnya.
            'products' => Product::active()->with(['variants' => fn ($v) => $v->active()->orderBy('sort_order')])
                ->get(['id', 'sku', 'name', 'sales_price', 'category_id']),
            'categories' => ProductCategory::orderBy('sort_order')->orderBy('name')->get(['id', 'name']),
        ]);
    }

    public function store(InvoiceRequest $request)
    {
        $data = $request->validated();
        $items = $data['items'];
        $serviceIds = $request->serviceIds();
        $saveAction = $request->saveAction();
        unset($data['items'], $data['service_ids'], $data['save_action']);

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

        if ($saveAction === 'confirm') {
            $invoice->markSent();

            return redirect()->route('invoices.show', $invoice)
                ->with('success', "Invoice {$invoice->number} disimpan dan ditandai terkirim.");
        }

        if ($saveAction === 'send') {
            return redirect()->route('invoices.show', $invoice)
                ->with('success', "Invoice {$invoice->number} disimpan sebagai draf. Pilih Kirim Email / WhatsApp untuk mengirim ke klien.");
        }

        return redirect()->route('invoices.show', $invoice)
            ->with('success', "Invoice {$invoice->number} berhasil dibuat.");
    }

    public function show(Invoice $invoice)
    {
        $invoice->load(['client', 'services', 'items', 'payments.confirmer', 'terminInvoices', 'parentInvoice', 'recurringPlan']);

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

        // Nilai kontrak induk sudah dialokasikan ke invoice termin. Mengubah
        // item/textra invoice induk membuat nilai kontrak dan total termin
        // tidak sinkron (mis. kontrak 1 juta, termin tetap 1 juta).
        if ($invoice->hasNonDraftTerms()) {
            return redirect()->route('invoices.show', $invoice)
                ->with('error', "Invoice {$invoice->number} sudah dipecah menjadi termin yang sudah dikirim sehingga nilai kontrak tidak dapat diubah.");
        }

        $invoice->load(['services', 'items']);

        return view('invoices.edit', [
            'invoice' => $invoice,
            'clients' => Client::orderBy('name')->get(['id', 'name']),
            'services' => Service::orderBy('name')->get(['id', 'client_id', 'name', 'price']),
            'products' => Product::active()->with(['variants' => fn ($v) => $v->active()->orderBy('sort_order')])
                ->get(['id', 'sku', 'name', 'sales_price', 'category_id']),
            'categories' => ProductCategory::orderBy('sort_order')->orderBy('name')->get(['id', 'name']),
        ]);
    }

    public function update(InvoiceRequest $request, Invoice $invoice)
    {
        if ($invoice->isTerminal()) {
            return redirect()->route('invoices.show', $invoice)
                ->with('error', "Invoice {$invoice->number} berstatus {$invoice->status->label()} sehingga tidak dapat diubah.");
        }

        // Sama seperti edit(): ubah nilai kontrak hanya boleh selama seluruh
        // termin masih draf. Setelah ada termin yang keluar, nilai kontrak
        // adalah hasil pembagian yang sudah tercatat di invoice termin.
        if ($invoice->hasNonDraftTerms()) {
            return redirect()->route('invoices.show', $invoice)
                ->with('error', "Invoice {$invoice->number} sudah dipecah menjadi termin yang sudah dikirim sehingga nilai kontrak tidak dapat diubah.");
        }

        $data = $request->validated();
        $items = $data['items'];
        $serviceIds = $request->serviceIds();
        $saveAction = $request->saveAction();
        unset($data['items'], $data['service_ids'], $data['save_action']);

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

        if ($saveAction === 'confirm') {
            $invoice->markSent();

            return redirect()->route('invoices.show', $invoice)
                ->with('success', "Invoice {$invoice->number} disimpan dan ditandai terkirim.");
        }

        if ($saveAction === 'send') {
            return redirect()->route('invoices.show', $invoice)
                ->with('success', "Invoice {$invoice->number} disimpan sebagai draf. Pilih Kirim Email / WhatsApp untuk mengirim ke klien.");
        }

        return redirect()->route('invoices.show', $invoice)
            ->with('success', "Invoice {$invoice->number} berhasil diperbarui.");
    }

    public function destroy(Invoice $invoice)
    {
        if ($invoice->status !== InvoiceStatus::Draft) {
            return redirect()->route('invoices.show', $invoice)
                ->with('error', 'Hanya invoice berstatus draf yang dapat dihapus.');
        }

        // Menghapus invoice induk akan cascade menghapus termin-nya. Kalau ada
        // termin yang sudah dikirim/lunas, cascade itu ikut menghapus invoice
        // berserta riwayat pembayarannya — data keuangan yang tidak bisa
        // dipulihkan. Blokir, dan suruh admin membatalkan termin-nya dulu.
        if (! $invoice->canBeDeletedSafely()) {
            return redirect()->route('invoices.show', $invoice)
                ->with('error', "Invoice {$invoice->number} sudah dipecah menjadi termin yang sudah dikirim/lunas sehingga tidak dapat dihapus. Batalkan invoice termin-nya terlebih dahulu.");
        }

        $number = $invoice->number;
        $invoice->delete();

        return redirect()->route('invoices.index')
            ->with('success', "Invoice {$number} dihapus.");
    }

    /**
     * Pecah invoice menjadi beberapa termin (F4-10), mis. 30%/30%/40%.
     *
     * Setiap termin menjadi invoice terpisah yang terhubung ke invoice induk.
     */
    public function createTermin(Invoice $invoice)
    {
        if (! $invoice->canSplitIntoTerms()) {
            return redirect()->route('invoices.show', $invoice)
                ->with('error', $invoice->hasTermins()
                    ? "Invoice {$invoice->number} sudah memiliki termin sehingga nilai kontrak tidak dapat dipecah lagi."
                    : "Invoice {$invoice->number} berstatus {$invoice->status->label()} sehingga tidak dapat dipecah menjadi termin.");
        }

        if ((int) $invoice->total <= 0) {
            return redirect()->route('invoices.show', $invoice)
                ->with('error', "Invoice {$invoice->number} belum punya nilai kontrak. Tambahkan item bernilai lebih dulu.");
        }

        $invoice->load(['client', 'services', 'items']);

        return view('invoices.termin.create', [
            'invoice' => $invoice,
        ]);
    }

    /** Jalankan pecahan termin dari form. */
    public function storeTermin(InvoiceTerminRequest $request, Invoice $invoice, InvoiceTerminSplitter $splitter)
    {
        try {
            $terms = $splitter->split($invoice, $request->terms());
        } catch (InvalidTerminSplit $e) {
            return back()
                ->withInput()
                ->with('error', $e->getMessage());
        }

        $count = $terms->count();

        return redirect()->route('invoices.show', $invoice)
            ->with('success', "Invoice {$invoice->number} dipecah menjadi {$count} termin.");
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
        // Invoice induk yang sudah dipecah bukan piutang — nilainya sudah
        // ada di invoice termin. Menagihnya di sini = penagihan ganda.
        if (! $invoice->isCollectible()) {
            return back()->with('error',
                "Invoice {$invoice->number} sudah dipecah menjadi termin sehingga pembayaran dicatat pada invoice termin-nya.");
        }

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

        // Invoice induk termin tidak boleh punya tautan bayar: halaman publiknya
        // menampilkan nilai kontrak PENUH, sehingga klien bisa mencicil lewat
        // tautan itu sekaligus lewat termin — nilainya terambil dua kali.
        if (! $invoice->isCollectible()) {
            return back()->with('error',
                "Invoice {$invoice->number} sudah dipecah menjadi termin. Bagikan tautan bayar invoice termin-nya.");
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
