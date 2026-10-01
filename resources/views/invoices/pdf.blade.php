<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <title>{{ $invoice->number }}</title>
    @php
        $business = config('crm.business');
        $bank = config('crm.bank');
        $methodLabels = [
            'bank_transfer' => 'Transfer bank',
            'cash' => 'Tunai',
            'qris' => 'QRIS',
            'ewallet' => 'E-wallet',
            'other' => 'Lainnya',
        ];
    @endphp
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: 'DejaVu Sans', sans-serif;
            font-size: 11px;
            color: #1e293b;
            line-height: 1.45;
        }
        .header { width: 100%; margin-bottom: 24px; }
        .header td { vertical-align: top; }
        .biz-name { font-size: 20px; font-weight: bold; color: #4f46e5; }
        .biz-detail { color: #64748b; font-size: 10px; margin-top: 2px; }
        .doc-title { font-size: 24px; font-weight: bold; letter-spacing: 2px; text-align: right; color: #0f172a; }
        .doc-number { text-align: right; font-size: 12px; margin-top: 2px; }
        .status-badge {
            display: inline-block; padding: 2px 10px; border-radius: 10px;
            font-size: 10px; font-weight: bold; background: #e0e7ff; color: #3730a3;
        }
        .parties { width: 100%; margin-bottom: 20px; }
        .parties td { vertical-align: top; }
        .box {
            border: 1px solid #e2e8f0; border-radius: 6px; padding: 10px 12px; width: 48%;
        }
        .box-title { font-size: 9px; text-transform: uppercase; letter-spacing: 1px; color: #94a3b8; margin-bottom: 4px; }
        .meta-table { width: 100%; margin-bottom: 20px; }
        .meta-table td { padding: 2px 0; vertical-align: top; }
        .meta-label { color: #64748b; width: 110px; }
        table.items { width: 100%; border-collapse: collapse; margin-bottom: 16px; }
        table.items th {
            background: #4f46e5; color: #ffffff; text-align: left;
            padding: 8px 10px; font-size: 10px; text-transform: uppercase; letter-spacing: 0.5px;
        }
        table.items td { padding: 8px 10px; border-bottom: 1px solid #e2e8f0; }
        table.items .num { text-align: right; }
        table.items .ctr { text-align: center; }
        .totals { width: 45%; margin-left: 55%; }
        .totals td { padding: 4px 8px; }
        .totals .label { color: #64748b; }
        .totals .grand td {
            border-top: 2px solid #0f172a; font-size: 13px; font-weight: bold; color: #0f172a;
        }
        .section-title {
            font-size: 10px; text-transform: uppercase; letter-spacing: 1px;
            color: #94a3b8; margin: 18px 0 6px 0;
        }
        .payment-box { border: 1px solid #e2e8f0; border-radius: 6px; padding: 10px 12px; background: #f8fafc; }
        .payment-box strong { color: #0f172a; }
        .notes { white-space: pre-line; }
        .payments-table { width: 100%; border-collapse: collapse; }
        .payments-table th {
            background: #f1f5f9; color: #475569; text-align: left;
            padding: 5px 8px; font-size: 9px; text-transform: uppercase;
        }
        .payments-table td { padding: 5px 8px; border-bottom: 1px solid #e2e8f0; }
        .footer {
            position: fixed; bottom: -30px; left: 0; right: 0;
            text-align: center; font-size: 9px; color: #94a3b8;
            border-top: 1px solid #e2e8f0; padding-top: 6px;
        }
        .footer-note { text-align: center; font-size: 9px; color: #64748b; margin-top: 24px; }
    </style>
</head>
<body>

<div class="footer">
    {{ $business['name'] }} · {{ $business['email'] }} · {{ $business['phone'] }}
</div>

<!-- Kop identitas usaha -->
<table class="header">
    <tr>
        <td style="width: 55%;">
            <div class="biz-name">{{ $business['name'] }}</div>
            <div class="biz-detail">
                {{ $business['address'] }}<br>
                {{ $business['email'] }} · {{ $business['phone'] }}
                @if (!empty($business['whatsapp']) && $business['whatsapp'] !== $business['phone'])
                    · WA {{ $business['whatsapp'] }}
                @endif
            </div>
        </td>
        <td style="width: 45%;">
            <div class="doc-title">INVOICE</div>
            <div class="doc-number">{{ $invoice->number }}</div>
            <div class="doc-number"><span class="status-badge">{{ strtoupper($invoice->status->label()) }}</span></div>
        </td>
    </tr>
</table>

<!-- Ditujukan kepada & meta -->
<table class="parties">
    <tr>
        <td style="width: 52%;">
            <div class="box">
                <div class="box-title">Ditagihkan kepada</div>
                <strong>{{ $invoice->client?->name ?? '—' }}</strong><br>
                @if ($invoice->client?->address)
                    {{ $invoice->client->address }}<br>
                @endif
                @if ($invoice->client?->email)
                    {{ $invoice->client->email }}<br>
                @endif
                @if ($invoice->client?->whatsapp)
                    WA: {{ $invoice->client->whatsapp }}
                @endif
            </div>
        </td>
        <td style="width: 48%;">
            <div class="box">
                <div class="box-title">Detail invoice</div>
                <table>
                    <tr><td class="meta-label">Tanggal terbit</td><td>: {{ tgl_id($invoice->issue_date) }}</td></tr>
                    <tr><td class="meta-label">Jatuh tempo</td><td>: {{ tgl_id($invoice->due_date) }}</td></tr>
                    @if ($invoice->service)
                        <tr><td class="meta-label">Layanan</td><td>: {{ $invoice->service->name }}</td></tr>
                    @endif
                    @if ($invoice->title)
                        <tr><td class="meta-label">Perihal</td><td>: {{ $invoice->title }}</td></tr>
                    @endif
                </table>
            </div>
        </td>
    </tr>
</table>

<!-- Tabel item -->
<table class="items">
    <thead>
        <tr>
            <th style="width: 52%;">Deskripsi</th>
            <th class="ctr" style="width: 10%;">Qty</th>
            <th class="num" style="width: 19%;">Harga satuan</th>
            <th class="num" style="width: 19%;">Jumlah</th>
        </tr>
    </thead>
    <tbody>
        @foreach ($invoice->items as $item)
            <tr>
                <td>{{ $item->description }}</td>
                <td class="ctr">{{ $item->quantity }}</td>
                <td class="num">{{ rupiah($item->unit_price) }}</td>
                <td class="num">{{ rupiah($item->amount) }}</td>
            </tr>
        @endforeach
    </tbody>
</table>

<!-- Total akhir (subtotal = total) -->
<table class="totals">
    <tr>
        <td class="label">Subtotal</td>
        <td class="num" style="text-align: right;">{{ rupiah($invoice->subtotal) }}</td>
    </tr>
    <tr class="grand">
        <td>TOTAL</td>
        <td style="text-align: right;">{{ rupiah($invoice->total) }}</td>
    </tr>
</table>

<!-- Instruksi pembayaran -->
<div class="section-title">Instruksi pembayaran</div>
<div class="payment-box">
    Transfer bank ke rekening berikut dan sertakan nomor invoice <strong>{{ $invoice->number }}</strong> pada berita transfer:<br><br>
    <table>
        <tr><td style="width: 130px; color: #64748b;">Bank</td><td>: <strong>{{ $bank['name'] }}</strong></td></tr>
        <tr><td style="color: #64748b;">Nomor rekening</td><td>: <strong>{{ $bank['account_number'] }}</strong></td></tr>
        <tr><td style="color: #64748b;">Atas nama</td><td>: <strong>{{ $bank['account_holder'] }}</strong></td></tr>
    </table>
</div>

<!-- Riwayat pembayaran (bila ada) -->
@if ($invoice->payments->isNotEmpty())
    <div class="section-title">Riwayat pembayaran</div>
    <table class="payments-table">
        <thead>
            <tr>
                <th>Tanggal</th>
                <th>Metode</th>
                <th>Status</th>
                <th style="text-align: right;">Nominal</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($invoice->payments as $payment)
                <tr>
                    <td>{{ $payment->paid_at?->format('d/m/Y') ?? '—' }}</td>
                    <td>{{ $methodLabels[$payment->method] ?? $payment->method }}</td>
                    <td>{{ $payment->status === 'confirmed' ? 'Terkonfirmasi' : 'Menunggu' }}</td>
                    <td style="text-align: right;">{{ rupiah($payment->amount) }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>
@endif

<!-- Catatan -->
@if ($invoice->notes)
    <div class="section-title">Catatan</div>
    <div class="notes">{{ $invoice->notes }}</div>
@endif

<p class="footer-note">{{ config('crm.invoice.footer_note') }}</p>

</body>
</html>
