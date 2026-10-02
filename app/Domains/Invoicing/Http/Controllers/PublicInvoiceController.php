<?php

namespace App\Domains\Invoicing\Http\Controllers;

use App\Domains\Invoicing\Enums\InvoiceStatus;
use App\Domains\Invoicing\Models\Invoice;
use App\Http\Controllers\Controller;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\Response;

/**
 * Halaman invoice publik (magic link) — tanpa login, diakses lewat
 * public_token 64 karakter. Tidak pernah membocorkan data klien lain:
 * setiap request hanya memuat satu invoice yang cocok dengan token.
 */
class PublicInvoiceController extends Controller
{
    /** Cari invoice berdasarkan token publik; token kosong/salah/dicabut -> 404. */
    private function findInvoice(string $token): Invoice
    {
        $invoice = Invoice::with(['client', 'services', 'items'])
            ->where('public_token', $token)
            ->first();

        abort_if($invoice === null, 404);

        return $invoice;
    }

    /** GET /pay/{token} */
    public function show(string $token): View
    {
        $invoice = $this->findInvoice($token);

        if ($invoice->status === InvoiceStatus::Cancelled) {
            return view('invoices.public-cancelled', [
                'invoice' => $invoice,
                'business' => config('crm.business'),
            ]);
        }

        // Invoice induk termin: nilainya sudah tercermin di invoice termin.
        // Menampilkannya sebagai tagihan penuh membuat klien bisa mencicil
        // nilai kontrak penuh lewat sini, lalu lagi lewat termin.
        return view('invoices.public', [
            'invoice' => $invoice,
            'business' => config('crm.business'),
            'isTerminParent' => ! $invoice->isCollectible(),
        ]);
    }

    /** GET /pay/{token}/pdf — dokumen resmi yang sama seperti route admin. */
    public function pdf(string $token): Response
    {
        $invoice = $this->findInvoice($token);
        $invoice->load(['client', 'services', 'items', 'payments']);

        return Pdf::loadView('invoices.pdf', ['invoice' => $invoice])
            ->setPaper('a4', 'portrait')
            ->download("{$invoice->number}.pdf");
    }

    /** POST /pay/{token}/payments — konfirmasi transfer oleh klien. */
    public function storePayment(Request $request, string $token): RedirectResponse
    {
        $invoice = $this->findInvoice($token);

        // Invoice induk termin bukan piutang — nilainya sudah ditagih lewat
        // invoice termin. Menerima konfirmasi transfer di sini memungkinkan
        // klien membayar nilai kontrak penuh dua kali.
        if (! $invoice->isCollectible()) {
            return back()->with('error', 'Invoice ini adalah invoice kontrak yang sudah dipecah menjadi termin. Bayar per termin invoice yang/dpunya tautannya masing-masing.');
        }

        // Invoice final (lunas/dibatalkan) tidak menerima konfirmasi baru.
        if ($invoice->isTerminal()) {
            return back()->with('error', 'Invoice ini sudah lunas atau dibatalkan sehingga konfirmasi pembayaran tidak dapat dikirim.');
        }

        $validated = $request->validate([
            'sender_name' => ['required', 'string', 'max:255'],
            'amount' => ['required', 'integer', 'min:1'],
            'paid_at' => ['required', 'date'],
            'note' => ['nullable', 'string', 'max:255'],
        ], [
            'sender_name.required' => 'Nama pengirim wajib diisi.',
            'amount.required' => 'Nominal transfer wajib diisi.',
            'amount.integer' => 'Nominal transfer harus bilangan bulat (IDR).',
            'amount.min' => 'Nominal transfer harus lebih dari nol.',
            'paid_at.required' => 'Tanggal transfer wajib diisi.',
        ]);

        $amount = (int) $validated['amount'];

        // Nominal tidak boleh melebihi total tagihan.
        if ($amount > (int) $invoice->total) {
            return back()->withInput()->withErrors([
                'amount' => 'Nominal tidak boleh melebihi total invoice ('.rupiah($invoice->total).').',
            ]);
        }

        $paidAt = Carbon::parse($validated['paid_at'])->toDateString();

        // Tolak dobel-submit sederhana: nominal + tanggal sama dalam 5 menit terakhir.
        $duplicate = $invoice->payments()
            ->where('amount', $amount)
            ->whereDate('paid_at', $paidAt)
            ->where('created_at', '>=', now()->subMinutes(5))
            ->exists();

        if ($duplicate) {
            return back()->withInput()->withErrors([
                'amount' => 'Konfirmasi dengan nominal dan tanggal yang sama baru saja dikirim. Mohon tunggu verifikasi admin.',
            ]);
        }

        $invoice->payments()->create([
            'sender_name' => $validated['sender_name'],
            'amount' => $amount,
            'method' => 'bank_transfer',
            'status' => 'pending',
            'paid_at' => $paidAt,
            'note' => $validated['note'] ?? null,
        ]);

        // Catatan: status invoice TIDAK berubah — admin yang mengonfirmasi.
        return redirect()->route('invoices.public.show', $token)
            ->with('success', 'Terima kasih! Konfirmasi transfer Anda sudah dikirim dan akan diverifikasi oleh admin.');
    }
}
