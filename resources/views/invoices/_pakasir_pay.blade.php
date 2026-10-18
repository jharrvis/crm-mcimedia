{{-- Form bayar online via Pakasir + QRIS Dinamis GoPay (backup) --}}
@php
    use App\Domains\Invoicing\Services\QrisDynamicService;

    $qrisSvc = app(QrisDynamicService::class);
    $qrisGopayEnabled = $qrisSvc->isEnabled() && ! $isPaid;
    $qrisGopayPayload = $qrisGopayEnabled ? $qrisSvc->payloadFor($invoice) : null;
    $qrisGopayAmount = $qrisGopayPayload ? $qrisSvc->payableAmount($invoice) : null;
@endphp
@if (config('pakasir.slug') && config('pakasir.api_key'))
    <div class="flex flex-wrap items-center gap-3">
        <select id="pakasir-method"
            class="rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm dark:border-slate-700 dark:bg-slate-800">
            <option value="qris">QRIS</option>
            @if ($qrisGopayEnabled)
                <option value="qris_gopay">QRIS GoPay</option>
            @endif
            <option value="bri_va">BRI Virtual Account</option>
            <option value="bni_va">BNI Virtual Account</option>
            <option value="cimb_niaga_va">CIMB Niaga VA</option>
            <option value="maybank_va">Maybank VA</option>
            <option value="permata_va">Permata VA</option>
            <option value="payment_link">Link Pembayaran</option>
        </select>
        <button id="btn-pakasir-pay"
            class="shrink-0 rounded-lg bg-emerald-600 px-5 py-2.5 text-sm font-semibold text-white hover:bg-emerald-700 disabled:opacity-50">
            Bayar Rp {{ number_format($invoice->total, 0, ',', '.') }}
        </button>
    </div>
    <div id="pakasir-result" class="mt-3 hidden text-sm"></div>

<script src="https://cdn.jsdelivr.net/npm/qrcodejs@1.0.0/qrcode.min.js"></script>
<script>
const qrisGopayPayload = @json($qrisGopayPayload);
const qrisGopayAmount = @json($qrisGopayAmount);

// Polling status generik — dipakai Pakasir & QRIS GoPay
function startPaidPolling(pollUrl, box, btn, originalBtnText) {
    const timer = setInterval(async () => {
        try {
            const sres = await fetch(pollUrl, { headers: { 'Accept': 'application/json' } });
            const sdata = await sres.json();
            if (sdata.is_paid) {
                clearInterval(timer);
                box.innerHTML = '<p class="rounded-lg bg-emerald-100 px-4 py-3 font-semibold text-emerald-800">Pembayaran berhasil! Invoice lunas.</p>';
                setTimeout(() => location.reload(), 1500);
            }
        } catch (e) { /* abaikan, coba lagi */ }
    }, 5000);
}

document.getElementById('btn-pakasir-pay').addEventListener('click', async function () {
    const btn = this;
    const method = document.getElementById('pakasir-method').value;
    const box = document.getElementById('pakasir-result');
    const originalBtnText = 'Bayar Rp {{ number_format($invoice->total, 0, ',', '.') }}';
    btn.disabled = true;
    btn.textContent = 'Memproses...';
    box.classList.add('hidden');

    // QRIS GoPay dinamis — render langsung, tanpa API call
    if (method === 'qris_gopay' && qrisGopayPayload) {
        const amountStr = Number(qrisGopayAmount).toLocaleString('id-ID');
        box.innerHTML = '<p class="mb-2 font-medium">Scan QRIS berikut dengan aplikasi apa saja (GoPay, DANA, OVO, m-banking):</p>'
            + '<div id="qris-gopay-code" class="rounded border bg-white p-2 inline-block"></div>'
            + '<p class="mt-2 font-semibold">Bayar tepat Rp ' + amountStr + ' (termasuk kode unik)</p>';
        box.classList.remove('hidden');
        new QRCode(document.getElementById('qris-gopay-code'), {
            text: qrisGopayPayload,
            width: 220,
            height: 220,
            correctLevel: QRCode.CorrectLevel.M,
        });
        btn.textContent = 'Menunggu pembayaran...';
        startPaidPolling('{{ route('invoices.public.qris.status', ['token' => $invoice->public_token]) }}', box, btn, originalBtnText);
        return;
    }

    try {
        const res = await fetch('{{ route('invoices.public.pakasir.transaction', ['token' => $invoice->public_token]) }}', {
            method: 'POST',
            headers: {
                'X-CSRF-TOKEN': '{{ csrf_token() }}',
                'Accept': 'application/json',
                'Content-Type': 'application/json',
            },
            body: JSON.stringify({ method }),
        });
        const data = await res.json();
        if (!res.ok) throw new Error(data.message || 'Gagal membuat transaksi');
        if (data.payment_link) {
            window.location.href = data.payment_link;
            return;
        }
        let html = '';
        if (data.qr_string) {
            html += '<p class="mb-2 font-medium">Scan QRIS berikut:</p>';
            html += '<img src="https://api.qrserver.com/v1/create-qr-code/?size=220x220&data='
                + encodeURIComponent(data.qr_string) + '" alt="QRIS" class="rounded border bg-white p-2">';
        }
        if (data.va_number) {
            html += '<p class="mb-1 font-medium">Nomor Virtual Account:</p>';
            html += '<p class="font-mono text-lg font-bold tracking-wider">' + data.va_number + '</p>';
        }
        if (data.expired_at) {
            html += '<p class="mt-2 text-xs text-gray-500">Berlaku hingga: ' + data.expired_at + '</p>';
        }
        box.innerHTML = html || '<p>Transaksi dibuat. Silakan selesaikan pembayaran.</p>';
        box.classList.remove('hidden');
        btn.textContent = 'Menunggu pembayaran...';
        startPaidPolling('{{ route('invoices.public.pakasir.status', ['token' => $invoice->public_token]) }}', box, btn, originalBtnText);
    } catch (e) {
        alert('Gagal: ' + e.message);
        btn.disabled = false;
        btn.textContent = originalBtnText;
    }
});
</script>
@endif
