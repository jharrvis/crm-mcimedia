{{-- Form bayar online via Pakasir — hanya tampil jika Pakasir dikonfigurasi --}}
@if (config('pakasir.slug') && config('pakasir.api_key'))
    <div class="flex flex-wrap items-center gap-3">
        <select id="pakasir-method"
            class="rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm dark:border-slate-700 dark:bg-slate-800">
            <option value="qris">QRIS</option>
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

<script>
document.getElementById('btn-pakasir-pay').addEventListener('click', async function () {
    const btn = this;
    const method = document.getElementById('pakasir-method').value;
    const box = document.getElementById('pakasir-result');
    btn.disabled = true;
    btn.textContent = 'Memproses...';
    box.classList.add('hidden');
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
    } catch (e) {
        alert('Gagal: ' + e.message);
        btn.disabled = false;
        btn.textContent = 'Bayar Rp {{ number_format($invoice->total, 0, ',', '.') }}';
    }
});
</script>
@endif