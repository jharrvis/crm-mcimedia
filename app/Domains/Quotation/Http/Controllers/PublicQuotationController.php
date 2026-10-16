<?php

namespace App\Domains\Quotation\Http\Controllers;

use App\Domains\Core\Models\ActivityLog;
use App\Domains\Quotation\Enums\QuotationStatus;
use App\Domains\Quotation\Models\Quotation;
use App\Domains\Quotation\Services\QuotationDelivery;
use App\Http\Controllers\Controller;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\Response;

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

        return back()->with('success', $accepted
            ? 'Terima kasih! Penawaran Anda telah kami terima.'
            : 'Terima kasih atas tanggapan Anda. Penawaran ditandai ditolak.');
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
