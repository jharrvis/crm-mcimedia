<?php

namespace App\Domains\Quotation\Http\Controllers;

use App\Domains\Core\Models\ActivityLog;
use App\Domains\Invoicing\Jobs\SendInvoiceEmailJob;
use App\Domains\Invoicing\Jobs\SendInvoiceWhatsappJob;
use App\Domains\Invoicing\Models\Invoice;
use App\Domains\Invoicing\Services\InvoiceDelivery;
use App\Domains\Quotation\Enums\QuotationStatus;
use App\Domains\Quotation\Exceptions\InvalidQuotationTransition;
use App\Domains\Quotation\Models\Quotation;
use App\Domains\Quotation\Services\QuotationConverter;
use App\Domains\Quotation\Services\QuotationDelivery;
use App\Http\Controllers\Controller;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Halaman penawaran publik (magic link) — tanpa login, diakses lewat
 * public_token 64 karakter. Cermin F2-4 (`PublicInvoiceController`).
 *
 * Klien hanya boleh merespons penawaran yang masih `sent` DAN belum lewat masa
 * berlaku; status lain (terminal, draf) hanya informational.
 */
class PublicQuotationController extends Controller
{
    /** Cari penawaran berdasarkan token publik; token salah/kosong/dicabut -> 404. */
    private function findQuotation(string $token): Quotation
    {
        $quotation = Quotation::with(['client', 'items'])
            ->where('public_token', $token)
            ->first();

        abort_if($quotation === null, 404);

        return $quotation;
    }

    /** GET /q/{token} */
    public function show(string $token): View
    {
        $quotation = $this->findQuotation($token);

        // Masa berlaku habis: jadikan final saat halaman dibuka supaya tombol
        // Setuju/Tolak hilang dan admin melihat status yang jujur. Hanya untuk
        // penawaran `sent` — status terminal lain (mis. accepted) dibiarkan apa
        // adanya, bukan ditimpa jadi kedaluwarsa. Cek ulang di bawah lock baris
        // supaya tidak menimpa perubahan yang datang di saat yang sama
        // (mis. disetujui tepat saat penarikan).
        if ($quotation->status === QuotationStatus::Sent && $quotation->isExpiredByDate()) {
            $quotation = DB::transaction(function () use ($quotation) {
                $locked = Quotation::query()->whereKey($quotation->id)->lockForUpdate()->first();

                if ($locked->status === QuotationStatus::Sent && $locked->isExpiredByDate()) {
                    $locked->markExpired();
                }

                return $locked;
            });
        }

        return view('quotations.public', [
            'quotation' => $quotation,
            'business' => config('crm.business'),
            'canBeAnswered' => $quotation->canBeAnswered(),
        ]);
    }

    /** GET /q/{token}/pdf — dokumen resmi penawaran, sama seperti route admin. */
    public function pdf(string $token): Response
    {
        $quotation = $this->findQuotation($token);
        $quotation->load(['client', 'items']);

        return Pdf::loadView('quotations.pdf', ['quotation' => $quotation])
            ->setPaper('a4', 'portrait')
            ->download("{$quotation->number}.pdf");
    }

    /** POST /q/{token}/accept — klien menyetujui penawaran. */
    public function accept(string $token): RedirectResponse
    {
        return $this->respond($token, QuotationStatus::Accepted);
    }

    /** POST /q/{token}/reject — klien menolak penawaran. */
    public function reject(string $token): RedirectResponse
    {
        return $this->respond($token, QuotationStatus::Rejected);
    }

    /** Jalur bersama setuju/tolak: guard kelayakan + transisi + jejak aktivitas. */
    private function respond(string $token, QuotationStatus $answer): RedirectResponse
    {
        $quotation = $this->findQuotation($token);

        // Serialisasi respons yang datang bersamaan: cek kelayakan ulang di
        // bawah lock baris, supaya jawaban kedua tidak menimpa status yang
        // sudah final (mis. habis disetujui admin/klien lain).
        [$quotation, $answerable] = DB::transaction(function () use ($quotation) {
            $locked = Quotation::query()
                ->whereKey($quotation->id)
                ->lockForUpdate()
                ->first();

            return [$locked, $locked->canBeAnswered()];
        });

        if (! $answerable) {
            return back()->with('error', $this->unanswerableMessage($quotation));
        }

        $accepted = $answer === QuotationStatus::Accepted;

        $accepted ? $quotation->markAccepted() : $quotation->markRejected();

        ActivityLog::record(
            $quotation,
            $accepted ? QuotationDelivery::EVENT_ACCEPTED : QuotationDelivery::EVENT_REJECTED,
            "Penawaran {$quotation->number} ".($accepted ? 'disetujui' : 'ditolak').' klien lewat halaman publik.'
        );

        if ($accepted) {
            $this->autoCreateAndSendInvoice($quotation);
        }

        return back()->with('success', $accepted
            ? 'Terima kasih! Penawaran Anda telah kami terima.'
            : 'Terima kasih atas tanggapan Anda. Penawaran ditandai ditolak.');
    }

    /**
     * Setelah klien menyetujui penawaran, buat invoice otomatis dan antre
     * pengiriman ke klien (email & WhatsApp bila memungkinkan).
     *
     * Kegagalan di sini TIDAK boleh menggagalkan respons "Setuju" ke klien:
     * semua error ditangkap dan dicatat ke log & activity log, sehingga admin
     * tetap bisa membuat invoice manual dari halaman quotation.
     */
    private function autoCreateAndSendInvoice(Quotation $quotation): void
    {
        try {
            $invoice = QuotationConverter::convert($quotation->fresh(['items', 'client']));
        } catch (InvalidQuotationTransition $e) {
            // Sudah pernah dikonversi (mis. klik ganda / race) — bukan error.
            Log::info('Auto-invoice dilewati: quotation sudah dikonversi.', [
                'quotation' => $quotation->number,
            ]);

            return;
        } catch (Throwable $e) {
            Log::error('Auto-invoice gagal dibuat.', [
                'quotation' => $quotation->number,
                'error' => $e->getMessage(),
            ]);

            ActivityLog::record(
                $quotation,
                QuotationDelivery::EVENT_AUTO_INVOICE_FAILED,
                "Gagal membuat invoice otomatis dari {$quotation->number}: {$e->getMessage()}. Buat invoice manual dari halaman admin."
            );

            return;
        }

        ActivityLog::record(
            $invoice,
            QuotationDelivery::EVENT_INVOICE_AUTO_CREATED,
            "Invoice {$invoice->number} dibuat otomatis dari penawaran {$quotation->number} yang disetujui klien."
        );

        $this->dispatchInvoiceDelivery($invoice);
    }

    /**
     * Antre pengiriman invoice ke klien: email bila ada alamat, WhatsApp bila
     * nomor valid dan Fonnte terkonfigurasi. Pengiriman yang tidak memungkinkan
     * dilewati dengan log (tanpa exception).
     */
    private function dispatchInvoiceDelivery(Invoice $invoice): void
    {
        $client = $invoice->client;

        $email = $client?->email;
        if (filled($email)) {
            SendInvoiceEmailJob::dispatch($invoice);
        } else {
            Log::info('Auto-invoice: pengiriman email dilewati, klien tidak punya alamat email.', [
                'invoice' => $invoice->number,
            ]);
        }

        $fonnteConfigured = (bool) config('crm.fonnte.enabled') && filled(config('crm.fonnte.token'));
        $whatsapp = InvoiceDelivery::normalizeWhatsapp($client?->whatsapp);

        if ($fonnteConfigured && filled($whatsapp)) {
            SendInvoiceWhatsappJob::dispatch($invoice);
        } else {
            Log::info('Auto-invoice: pengiriman WhatsApp dilewati.', [
                'invoice' => $invoice->number,
                'reason' => $fonnteConfigured ? 'klien tidak punya nomor WhatsApp' : 'Fonnte belum dikonfigurasi',
            ]);
        }
    }

    /** Alasan penolakan respons, dibedakan per penyebab agar klien tidak bingung. */
    private function unanswerableMessage(Quotation $quotation): string
    {
        if ($quotation->status !== QuotationStatus::Sent) {
            return "Penawaran {$quotation->number} sudah berstatus {$quotation->status->label()} sehingga tidak dapat direspons lagi.";
        }

        return "Penawaran {$quotation->number} sudah melewati masa berlaku sehingga tidak dapat direspons.";
    }
}
