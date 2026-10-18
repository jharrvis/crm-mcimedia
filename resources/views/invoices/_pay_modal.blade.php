{{-- Modal pembayaran gaya Midtrans Snap: QRIS GoPay urutan pertama, lalu Pakasir --}}
@php
    use App\Domains\Invoicing\Services\QrisDynamicService;

    $qrisSvc = app(QrisDynamicService::class);
    $qrisGopayEnabled = $qrisSvc->isEnabled() && ! $isPaid;
    $qrisGopayPayload = $qrisGopayEnabled ? $qrisSvc->payloadFor($invoice) : null;
    $qrisGopayAmount = $qrisGopayPayload ? $qrisSvc->payableAmount($invoice) : null;
    $pakasirEnabled = config('pakasir.slug') && config('pakasir.api_key');
@endphp
@if ($pakasirEnabled || $qrisGopayEnabled)
<div class="mt-4">
    {{-- Spacer agar konten tidak tertutup tombol sticky di mobile --}}
    <div class="h-16 sm:hidden"></div>
    <div class="fixed inset-x-0 bottom-0 z-40 border-t border-slate-200 bg-white/95 p-3 backdrop-blur-sm dark:border-slate-700 dark:bg-slate-900/95 sm:static sm:border-0 sm:bg-transparent sm:p-0 sm:backdrop-blur-none dark:sm:bg-transparent">
        <button id="btn-open-pay-modal"
            class="w-full rounded-lg bg-emerald-600 px-5 py-3.5 text-base font-semibold text-white shadow-lg hover:bg-emerald-700 sm:py-3 sm:text-sm sm:shadow-none">
            Bayar Rp {{ number_format($invoice->total, 0, ',', '.') }}
        </button>
        <p class="mt-1.5 text-center text-xs text-slate-500">Status invoice otomatis terupdate setelah pembayaran berhasil.</p>
    </div>
</div>

{{-- Modal --}}
<div id="pay-modal" class="fixed inset-0 z-50 hidden items-center justify-center bg-black/70 p-4 backdrop-blur-sm">
    <div class="max-h-[90vh] w-full max-w-md overflow-hidden rounded-xl bg-white shadow-2xl dark:bg-slate-900">
        {{-- Header --}}
        <div class="flex items-center justify-between bg-slate-800 px-5 py-4 text-white">
            <div>
                <div class="text-sm font-semibold">{{ config('crm.business.name', 'MCI Media') }}</div>
                <div class="text-xs text-slate-300">{{ $invoice->number }}</div>
            </div>
            <button id="pay-modal-close" class="rounded p-1 text-xl leading-none hover:bg-slate-700">&times;</button>
        </div>
        {{-- Total --}}
        <div class="border-b border-slate-200 px-5 py-4 dark:border-slate-700">
            <div class="text-xs text-slate-500">Total</div>
            <div class="text-2xl font-bold text-slate-900 dark:text-white">Rp {{ number_format($invoice->total, 0, ',', '.') }}</div>
        </div>
        {{-- Daftar metode --}}
        <div id="pay-method-list" class="max-h-[50vh] overflow-y-auto px-2 py-2">
            <div class="px-3 py-2 text-xs font-semibold uppercase text-slate-400">Pilih metode pembayaran</div>
            @if ($qrisGopayEnabled)
            <button data-method="qris_gopay"
                class="pay-method-btn flex w-full items-center justify-between rounded-lg px-3 py-3 text-left hover:bg-slate-100 dark:hover:bg-slate-800">
                <span class="flex items-center gap-3">
                    <img src="{{ asset('images/pay/qris.png') }}" alt="QRIS" class="h-12 w-12 object-contain">
                    <span>
                        <span class="block text-sm font-semibold text-slate-900 dark:text-white">QRIS <span class="ml-1 rounded bg-emerald-100 px-1.5 py-0.5 text-[10px] font-bold text-emerald-700">REKOMENDASI</span></span>
                        <span class="block text-xs text-slate-500">GoPay, DANA, OVO, m-banking — Rp {{ number_format($qrisGopayAmount, 0, ',', '.') }}</span>
                    </span>
                </span>
                <span class="text-slate-400">&rsaquo;</span>
            </button>
            @endif
            @if ($pakasirEnabled)
            <button data-method="qris"
                class="pay-method-btn flex w-full items-center justify-between rounded-lg px-3 py-3 text-left hover:bg-slate-100 dark:hover:bg-slate-800">
                <span class="flex items-center gap-3">
                    <img src="{{ asset('images/pay/qris.png') }}" alt="QRIS" class="h-12 w-12 object-contain">
                    <span>
                        <span class="block text-sm font-semibold text-slate-900 dark:text-white">QRIS</span>
                        <span class="block text-xs text-slate-500">Via Pakasir</span>
                    </span>
                </span>
                <span class="text-slate-400">&rsaquo;</span>
            </button>
            <div class="px-3 py-2 text-xs font-semibold uppercase text-slate-400">Virtual Account</div>
            @foreach (['bri_va' => ['BRI', 'bri.png'], 'bni_va' => ['BNI', 'bni.png'], 'cimb_niaga_va' => ['CIMB Niaga', 'cimb.png'], 'maybank_va' => ['Maybank', 'maybank.png'], 'permata_va' => ['Permata', 'permata.webp']] as $code => [$bank, $logo])
            <button data-method="{{ $code }}"
                class="pay-method-btn flex w-full items-center justify-between rounded-lg px-3 py-3 text-left hover:bg-slate-100 dark:hover:bg-slate-800">
                <span class="flex items-center gap-3">
                    <img src="{{ asset('images/pay/' . $logo) }}" alt="{{ $bank }}" class="h-12 w-12 object-contain">
                    <span class="text-sm font-semibold text-slate-900 dark:text-white">{{ $bank }} Virtual Account</span>
                </span>
                <span class="text-slate-400">&rsaquo;</span>
            </button>
            @endforeach
            <button data-method="payment_link"
                class="pay-method-btn flex w-full items-center justify-between rounded-lg px-3 py-3 text-left hover:bg-slate-100 dark:hover:bg-slate-800">
                <span class="text-sm font-semibold text-slate-900 dark:text-white">Link Pembayaran</span>
                <span class="text-slate-400">&rsaquo;</span>
            </button>
            @endif
        </div>
        {{-- Detail pembayaran --}}
        <div id="pay-method-detail" class="hidden max-h-[60vh] overflow-y-auto px-5 py-4">
            <button id="pay-back-btn" class="mb-3 text-sm text-emerald-600 hover:underline">&larr; Pilih metode lain</button>
            <div id="pay-detail-content" class="text-sm"></div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/qrcodejs@1.0.0/qrcode.min.js"></script>
<script>
(function () {
    const modal = document.getElementById('pay-modal');
    const listView = document.getElementById('pay-method-list');
    const detailView = document.getElementById('pay-method-detail');
    const detailContent = document.getElementById('pay-detail-content');
    const qrisGopayPayload = @json($qrisGopayPayload);
    const qrisGopayAmount = @json($qrisGopayAmount);
    let pollTimer = null;

    function openModal() {
        modal.classList.remove('hidden');
        modal.classList.add('flex');
        showList();
    }
    function closeModal() {
        modal.classList.add('hidden');
        modal.classList.remove('flex');
        if (pollTimer) clearInterval(pollTimer);
    }
    function showList() {
        if (pollTimer) clearInterval(pollTimer);
        detailView.classList.add('hidden');
        listView.classList.remove('hidden');
    }
    function showDetail(html) {
        listView.classList.add('hidden');
        detailContent.innerHTML = html;
        detailView.classList.remove('hidden');
    }
    // Ubah error mentah (berisi JSON) jadi pesan yang enak dibaca user
    function friendlyError(raw) {
        let msg = String(raw || '');
        // Cari JSON {"message":"..."} yang tertanam di dalam string
        const m = msg.match(/\{"message"\s*:\s*"([^"]+)"/);
        if (m) msg = m[1];
        // Buang sisa JSON mentah bila masih ada
        msg = msg.replace(/\{.*\}\s*$/, '').trim();
        if (!msg) msg = 'Terjadi kesalahan. Silakan coba lagi.';
        return '<div class="rounded-lg bg-red-50 px-4 py-4 text-center dark:bg-red-900/20">'
            + '<p class="font-semibold text-red-700 dark:text-red-300">Pembayaran gagal dibuat</p>'
            + '<p class="mt-1 text-sm text-red-600 dark:text-red-400">' + msg.replace(/</g, '&lt;') + '</p>'
            + '<p class="mt-2 text-xs text-slate-500">Silakan pilih metode lain atau coba lagi.</p>'
            + '</div>';
    }
    function startPolling(url) {
        if (pollTimer) clearInterval(pollTimer);
        pollTimer = setInterval(async () => {
            try {
                const res = await fetch(url, { headers: { 'Accept': 'application/json' } });
                const data = await res.json();
                if (data.is_paid) {
                    clearInterval(pollTimer);
                    showDetail('<div class="rounded-lg bg-emerald-100 px-4 py-6 text-center font-semibold text-emerald-800">Pembayaran berhasil!<br>Invoice lunas.</div>');
                    setTimeout(() => location.reload(), 1800);
                }
            } catch (e) {}
        }, 5000);
    }

    document.getElementById('btn-open-pay-modal').addEventListener('click', openModal);
    document.getElementById('pay-modal-close').addEventListener('click', closeModal);
    document.getElementById('pay-back-btn').addEventListener('click', showList);
    modal.addEventListener('click', function (e) { if (e.target === modal) closeModal(); });

    document.querySelectorAll('.pay-method-btn').forEach(function (btn) {
        btn.addEventListener('click', async function () {
            const method = btn.dataset.method;

            // QRIS GoPay dinamis — render langsung
            if (method === 'qris_gopay' && qrisGopayPayload) {
                const amountStr = Number(qrisGopayAmount).toLocaleString('id-ID');
                showDetail(
                    '<div class="mb-3 flex items-center justify-center gap-3">'
                    + '<img src=\'{{ asset('images/pay/qris.png') }}\' alt="QRIS" class="h-12 w-12 object-contain">'
                    + '<img src=\'{{ asset('images/pay/gopay.png') }}\' alt="GoPay" class="h-12 w-12 object-contain">'
                    + '</div>'
                    + '<p class="mb-2 text-center font-medium">Scan QRIS berikut:</p>'
                    + '<div class="flex justify-center"><div id="qris-gopay-code" class="rounded border bg-white p-3 inline-block"></div></div>'
                    + '<p class="mt-3 text-center font-semibold">Bayar tepat Rp ' + amountStr + '</p>'
                    + '<p class="mt-1 text-center text-xs text-slate-500">GoPay, DANA, OVO, m-banking — nominal sudah termasuk kode unik</p>'
                    + '<p id="qris-wait" class="mt-3 text-center text-sm text-slate-500">Menunggu pembayaran...</p>'
                );
                new QRCode(document.getElementById('qris-gopay-code'), {
                    text: qrisGopayPayload, width: 220, height: 220, correctLevel: QRCode.CorrectLevel.M,
                });
                startPolling('{{ route('invoices.public.qris.status', ['token' => $invoice->public_token]) }}');
                return;
            }

            // Metode Pakasir — via API
            showDetail('<p class="text-slate-500">Memproses...</p>');
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
                if (data.payment_link) { window.location.href = data.payment_link; return; }
                let html = '';
                if (data.qr_string) {
                    html += '<p class="mb-2 font-medium">Scan QRIS berikut:</p>';
                    html += '<div class="flex justify-center"><img src="https://api.qrserver.com/v1/create-qr-code/?size=220x220&data='
                        + encodeURIComponent(data.qr_string) + '" alt="QRIS" class="rounded border bg-white p-3"></div>';
                }
                if (data.va_number) {
                    html += '<p class="mb-1 font-medium">Nomor Virtual Account:</p>';
                    html += '<p class="text-center font-mono text-xl font-bold tracking-wider">' + data.va_number + '</p>';
                }
                if (data.expired_at) {
                    html += '<p class="mt-2 text-center text-xs text-gray-500">Berlaku hingga: ' + data.expired_at + '</p>';
                }
                html += '<p id="qris-wait" class="mt-3 text-center text-sm text-slate-500">Menunggu pembayaran...</p>';
                showDetail(html || '<p>Transaksi dibuat. Silakan selesaikan pembayaran.</p>');
                startPolling('{{ route('invoices.public.pakasir.status', ['token' => $invoice->public_token]) }}');
            } catch (e) {
                showDetail(friendlyError(e.message));
            }
        });
    });
})();
</script>
@endif
