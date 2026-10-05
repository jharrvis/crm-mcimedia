{{-- Tombol Bayar via Midtrans Snap — hanya tampil jika Midtrans dikonfigurasi --}}
@if (config('midtrans.server_key') && config('midtrans.client_key'))
<div class="mb-6 rounded-lg border border-emerald-200 bg-emerald-50 p-4 dark:border-emerald-800 dark:bg-emerald-950">
    <div class="flex items-center justify-between gap-4">
        <div>
            <p class="font-semibold text-emerald-800 dark:text-emerald-200">Bayar Online</p>
            <p class="text-sm text-emerald-700 dark:text-emerald-300">
                QRIS, Virtual Account, GoPay & lainnya via Midtrans
            </p>
        </div>
        <button id="btn-midtrans-pay"
            class="shrink-0 rounded-lg bg-emerald-600 px-5 py-2.5 text-sm font-semibold text-white hover:bg-emerald-700 disabled:opacity-50">
            Bayar Rp {{ number_format($invoice->total, 0, ',', '.') }}
        </button>
    </div>
</div>

<script src="{{ config('midtrans.snap_url') }}" data-client-key="{{ config('midtrans.client_key') }}"></script>
<script>
document.getElementById('btn-midtrans-pay').addEventListener('click', async function () {
    const btn = this;
    btn.disabled = true;
    btn.textContent = 'Memproses...';
    try {
        const res = await fetch('{{ route('invoices.public.midtrans.token', ['token' => $invoice->public_token]) }}', {
            method: 'POST',
            headers: {
                'X-CSRF-TOKEN': '{{ csrf_token() }}',
                'Accept': 'application/json',
            },
        });
        const data = await res.json();
        if (!data.snap_token) throw new Error(data.message || 'Gagal membuat transaksi');
        window.snap.pay(data.snap_token, {
            onSuccess: function () { location.reload(); },
            onPending: function () { location.reload(); },
            onClose: function () { btn.disabled = false; btn.textContent = 'Bayar Rp {{ number_format($invoice->total, 0, ',', '.') }}'; },
        });
    } catch (e) {
        alert('Gagal: ' + e.message);
        btn.disabled = false;
        btn.textContent = 'Bayar Rp {{ number_format($invoice->total, 0, ',', '.') }}';
    }
});
</script>
@endif
