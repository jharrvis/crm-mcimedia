{{-- Pembayaran via QRIS Dinamis (backup) --}}
@php
    use App\Domains\Invoicing\Services\QrisDynamicService;

    $qris = app(QrisDynamicService::class);
    $qrisPayload = $qris->isEnabled() && ! $isPaid ? $qris->payloadFor($invoice) : null;
    $qrisAmount = $qrisPayload ? $qris->payableAmount($invoice) : null;
@endphp
@if ($qrisPayload)
<div class="mt-6 rounded-xl border border-slate-200 bg-white p-6 dark:border-slate-800 dark:bg-slate-900">
    <h2 class="mb-1 text-sm font-semibold uppercase text-slate-500">Bayar via QRIS</h2>
    <p class="mb-4 text-sm text-slate-500">
        Scan QR di bawah dengan aplikasi apa saja (GoPay, DANA, OVO, m-banking).
        Nominal sudah terkunci — <strong>bayar tepat Rp {{ number_format($qrisAmount, 0, ',', '.') }}</strong>
        (termasuk kode unik). Status invoice otomatis lunas setelah pembayaran terdeteksi.
    </p>
    <div class="flex flex-col items-center gap-3">
        <div id="qris-code" class="rounded-lg border border-slate-200 bg-white p-3"></div>
        <div class="text-center">
            <div class="text-2xl font-bold text-slate-900 dark:text-white">Rp {{ number_format($qrisAmount, 0, ',', '.') }}</div>
            <div class="text-xs text-slate-500">{{ $invoice->number }}</div>
        </div>
        <div id="qris-status" class="text-sm text-slate-500">Menunggu pembayaran...</div>
    </div>
</div>
<script src="https://cdn.jsdelivr.net/npm/qrcodejs@1.0.0/qrcode.min.js"></script>
<script>
    new QRCode(document.getElementById('qris-code'), {
        text: @json($qrisPayload),
        width: 256,
        height: 256,
        correctLevel: QRCode.CorrectLevel.M,
    });

    // Polling status tiap 5 detik — auto update tanpa refresh (seperti Pakasir)
    (function () {
        const pollUrl = '{{ route('invoices.public.qris.status', ['token' => $invoice->public_token]) }}';
        const statusEl = document.getElementById('qris-status');
        const timer = setInterval(async () => {
            try {
                const res = await fetch(pollUrl, { headers: { 'Accept': 'application/json' } });
                const data = await res.json();
                if (data.is_paid) {
                    clearInterval(timer);
                    statusEl.innerHTML = '<span class="rounded-lg bg-emerald-100 px-4 py-2 font-semibold text-emerald-800">Pembayaran berhasil! Invoice lunas.</span>';
                    setTimeout(() => location.reload(), 1500);
                }
            } catch (e) { /* abaikan, coba lagi */ }
        }, 5000);
    })();
</script>
@endif
