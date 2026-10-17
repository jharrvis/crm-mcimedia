<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <title>{{ $invoice->number }}</title>
    @php
        $business = config('crm.business');
        $bankAccounts = crm_bank_accounts();
        $methodLabels = [
            'bank_transfer' => 'Transfer bank',
            'cash' => 'Tunai',
            'qris' => 'QRIS',
            'ewallet' => 'E-wallet',
            'other' => 'Lainnya',
        ];
        $badge = [
            'draft'     => ['bg' => '#fffbeb', 'tx' => '#b45309', 'bd' => '#fde68a', 'label' => 'DRAF'],
            'sent'      => ['bg' => '#eff6ff', 'tx' => '#1d4ed8', 'bd' => '#bfdbfe', 'label' => 'TERKIRIM'],
            'paid'      => ['bg' => '#ecfdf5', 'tx' => '#047857', 'bd' => '#a7f3d0', 'label' => 'LUNAS'],
            'overdue'   => ['bg' => '#fff1f2', 'tx' => '#e11d48', 'bd' => '#fecdd3', 'label' => 'JATUH TEMPO'],
            'cancelled' => ['bg' => '#f1f5f9', 'tx' => '#64748b', 'bd' => '#e2e8f0', 'label' => 'BATAL'],
        ][$invoice->status->value] ?? ['bg' => '#f1f5f9', 'tx' => '#64748b', 'bd' => '#e2e8f0', 'label' => strtoupper($invoice->status->label())];
    @endphp
    <style>
        @page { margin: 0; }
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: 'DejaVu Sans', sans-serif;
            font-size: 10px;
            color: #0f172a;
            line-height: 1.5;
            background: #ffffff;
        }
        .mono { font-family: 'DejaVu Sans Mono', monospace; }
        table { border-collapse: collapse; }
        /* Accent bar */
        table.accent { width: 100%; }
        table.accent td { height: 8px; padding: 0; }
        /* Content wrapper */
        .content { padding: 36px 44px 60px 44px; }
        /* Header */
        table.header { width: 100%; margin-bottom: 26px; }
        table.header td { vertical-align: top; }
        .badge-logo {
            width: 46px; height: 46px; background: #0f172a; border-radius: 9px;
            text-align: center; vertical-align: middle;
        }
        .badge-logo .mci { color: #ffffff; font-size: 13px; font-weight: bold; letter-spacing: -0.5px; }
        .badge-logo .media { color: #818cf8; font-size: 6px; font-weight: bold; letter-spacing: 2px; }
        .biz-name { font-size: 17px; font-weight: bold; color: #0f172a; }
        .biz-addr { font-size: 10px; color: #64748b; margin-top: 2px; line-height: 1.5; }
        .biz-contact { font-size: 10px; color: #475569; margin-top: 4px; }
        .doc-label { font-size: 9px; font-weight: bold; text-transform: uppercase; letter-spacing: 2px; color: #4f46e5; }
        .doc-title { font-size: 23px; font-weight: bold; color: #0f172a; margin-top: 2px; }
        .doc-chips { margin-top: 8px; text-align: right; }
        .num-chip {
            font-family: 'DejaVu Sans Mono', monospace; font-size: 10px; font-weight: bold; color: #334155;
            background: #f1f5f9; border: 1px solid #e2e8f0; border-radius: 4px; padding: 4px 9px;
        }
        .status-chip {
            font-size: 9px; font-weight: bold; text-transform: uppercase; letter-spacing: 1px;
            border-radius: 4px; padding: 4px 9px; border: 1px solid;
            background: {{ $badge['bg'] }}; color: {{ $badge['tx'] }}; border-color: {{ $badge['bd'] }};
        }
        /* Cards */
        table.cards { width: 100%; margin-bottom: 22px; }
        table.cards > tbody > tr > td { vertical-align: top; }
        .card {
            background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 9px; padding: 14px 16px;
        }
        .card-title { font-size: 8px; font-weight: bold; text-transform: uppercase; letter-spacing: 1.5px; color: #94a3b8; margin-bottom: 8px; }
        .client-name { font-size: 12px; font-weight: bold; color: #0f172a; }
        .client-meta { font-size: 10px; color: #475569; margin-top: 4px; line-height: 1.55; }
        .kv { width: 100%; margin-top: 8px; border-top: 1px solid #e2e8f0; }
        .kv td { padding: 4px 0; font-size: 10px; vertical-align: top; }
        .kv .k { color: #94a3b8; width: 44px; }
        .kv .v { color: #334155; }
        .detail-row { padding: 5px 0; border-bottom: 1px solid #e2e8f0; }
        .detail-row td { padding: 5px 0; font-size: 10px; vertical-align: top; }
        .detail-row .k { color: #64748b; }
        .detail-row .v { text-align: right; font-weight: bold; color: #0f172a; }
        .detail-row .v-red { text-align: right; font-weight: bold; color: #e11d48; }
        .detail-row .v-indigo { text-align: right; font-weight: bold; color: #4338ca; }
        /* Items table */
        table.items { width: 100%; margin-bottom: 20px; border: 1px solid #e2e8f0; }
        table.items th {
            background: #0f172a; color: #ffffff; font-size: 8px; font-weight: bold;
            text-transform: uppercase; letter-spacing: 1px; padding: 10px 12px; text-align: left;
        }
        table.items th.ctr, table.items td.ctr { text-align: center; }
        table.items th.num, table.items td.num { text-align: right; }
        table.items td { padding: 10px 12px; font-size: 10px; border-top: 1px solid #e2e8f0; vertical-align: top; }
        /* Bottom: payment + totals */
        table.bottom { width: 100%; margin-bottom: 26px; }
        table.bottom > tbody > tr > td { vertical-align: top; }
        .pay-card { background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 9px; padding: 14px 16px; }
        .pay-card .card-title { color: #0f172a; }
        .pay-card p { font-size: 10px; color: #475569; line-height: 1.6; }
        .bank { background: #ffffff; border: 1px solid #e2e8f0; border-radius: 7px; padding: 10px 12px; margin-top: 10px; }
        .bank .bank-name {
            display: inline-block; font-size: 8px; font-weight: bold; text-transform: uppercase; letter-spacing: 1px;
            border-radius: 3px; padding: 2px 7px; border: 1px solid; margin-bottom: 6px;
        }
        .bank table { width: 100%; }
        .bank td { font-size: 10px; padding: 1px 0; vertical-align: top; }
        .bank .bl { color: #94a3b8; font-size: 9px; width: 92px; }
        .bank .bv { font-weight: bold; color: #0f172a; }
        .bank .bv-mono { font-family: 'DejaVu Sans Mono', monospace; font-weight: bold; color: #0f172a; font-size: 12px; }
        .totals-card { background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 9px; padding: 14px 16px; }
        .totals-card table { width: 100%; }
        .totals-card td { padding: 4px 0; font-size: 10px; }
        .totals-card .t-label { color: #64748b; }
        .totals-card .t-val { text-align: right; font-weight: bold; color: #0f172a; }
        .totals-card .grand td { border-top: 1px solid #e2e8f0; padding-top: 9px; }
        .totals-card .grand .t-label { font-size: 10px; font-weight: bold; text-transform: uppercase; letter-spacing: 1px; color: #0f172a; }
        .totals-card .grand .t-val { font-size: 15px; font-weight: bold; color: #4338ca; }
        /* Payment history */
        .section-title { font-size: 8px; font-weight: bold; text-transform: uppercase; letter-spacing: 1.5px; color: #94a3b8; margin: 0 0 8px 0; }
        table.payments { width: 100%; margin-bottom: 20px; border: 1px solid #e2e8f0; }
        table.payments th {
            background: #f1f5f9; color: #475569; font-size: 8px; font-weight: bold;
            text-transform: uppercase; letter-spacing: 1px; padding: 7px 10px; text-align: left;
        }
        table.payments td { padding: 7px 10px; font-size: 10px; border-top: 1px solid #e2e8f0; }
        table.payments td.num { text-align: right; }
        /* Notes */
        .notes-card { background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 9px; padding: 14px 16px; margin-bottom: 20px; }
        .notes-card p { font-size: 10px; color: #334155; line-height: 1.6; white-space: pre-line; }
        /* Signature */
        table.sign { width: 100%; margin-top: 6px; }
        table.sign td { font-size: 10px; color: #64748b; vertical-align: top; }
        table.sign .spacer { height: 48px; }
        table.sign .name { font-weight: bold; color: #0f172a; border-top: 1px solid #cbd5e1; display: inline-block; padding-top: 6px; margin-top: 4px; }
        /* Footer */
        .footer {
            position: fixed; bottom: 0; left: 0; right: 0;
            background: #0f172a; color: #94a3b8; font-size: 9px;
            padding: 11px 44px;
        }
        .footer table { width: 100%; }
        .footer .docnum { text-align: right; font-family: 'DejaVu Sans Mono', monospace; color: #64748b; }
        .footer-note { text-align: center; font-size: 9px; color: #64748b; margin-top: 18px; }
    </style>
</head>
<body>

<!-- Accent bar -->
<table class="accent"><tr>
    <td style="width: 34%; background: #0f172a;"></td>
    <td style="width: 33%; background: #4338ca;"></td>
    <td style="width: 33%; background: #10b981;"></td>
</tr></table>

<div class="content">

    <!-- Header -->
    <table class="header">
        <tr>
            <td style="width: 60%;">
                <table><tr>
                    <td style="vertical-align: top; padding-right: 12px;">
                        <div class="badge-logo">
                            <div class="mci">MCI</div>
                            <div class="media">MEDIA</div>
                        </div>
                    </td>
                    <td style="vertical-align: top;">
                        <div class="biz-name">{{ $business['name'] }}</div>
                        <div class="biz-addr">{{ $business['address'] }}</div>
                        <div class="biz-contact">{{ $business['email'] }} &nbsp;•&nbsp; <span class="mono">{{ $business['phone'] }}</span></div>
                    </td>
                </tr></table>
            </td>
            <td style="width: 40%; text-align: right;">
                <div class="doc-label">Tagihan Pembayaran</div>
                <div class="doc-title">INVOICE</div>
                <div class="doc-chips">
                    <span class="num-chip">{{ $invoice->number }}</span>&nbsp;
                    <span class="status-chip">{{ $badge['label'] }}</span>
                </div>
            </td>
        </tr>
    </table>

    <!-- Client & detail cards -->
    <table class="cards">
        <tr>
            <td style="width: 42%; padding-right: 10px;">
                <div class="card">
                    <div class="card-title">Ditagihkan Kepada</div>
                    <div class="client-name">{{ $invoice->client?->name ?? '—' }}</div>
                    @if ($invoice->client?->address)
                        <div class="client-meta">{{ $invoice->client->address }}</div>
                    @endif
                    <table class="kv">
                        @if ($invoice->client?->email)
                        <tr><td class="k">Email</td><td class="v">: {{ $invoice->client->email }}</td></tr>
                        @endif
                        @if ($invoice->client?->whatsapp)
                        <tr><td class="k">WA</td><td class="v mono">: {{ $invoice->client->whatsapp }}</td></tr>
                        @endif
                    </table>
                </div>
            </td>
            <td style="width: 58%; padding-left: 10px;">
                <div class="card">
                    <div class="card-title">Detail Invoice</div>
                    <table style="width: 100%;">
                        <tr class="detail-row">
                            <td class="k">Tanggal terbit</td>
                            <td class="v mono">{{ tgl_id($invoice->issue_date) }}</td>
                        </tr>
                        <tr class="detail-row">
                            <td class="k">Jatuh tempo</td>
                            <td class="v-red mono">{{ tgl_id($invoice->due_date) }}</td>
                        </tr>
                        @if ($invoice->services->isNotEmpty())
                        <tr class="detail-row">
                            <td class="k">Layanan</td>
                            <td class="v-indigo mono">{{ $invoice->services->pluck('name')->join(', ') }}</td>
                        </tr>
                        @endif
                        @if ($invoice->title)
                        <tr>
                            <td class="k" style="padding-top: 6px;">Perihal</td>
                            <td class="v" style="padding-top: 6px;">{{ $invoice->title }}</td>
                        </tr>
                        @endif
                    </table>
                </div>
            </td>
        </tr>
    </table>

    <!-- Items -->
    <table class="items">
        <thead>
            <tr>
                <th class="ctr" style="width: 36px;">No</th>
                <th>Deskripsi</th>
                <th class="ctr" style="width: 44px;">Qty</th>
                <th class="num" style="width: 110px;">Harga Satuan</th>
                <th class="num" style="width: 110px;">Jumlah</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($invoice->items as $i => $item)
            <tr>
                <td class="ctr mono" style="color: #94a3b8;">{{ $i + 1 }}</td>
                <td>{{ $item->description }}</td>
                <td class="ctr mono">{{ $item->quantity }}</td>
                <td class="num mono">{{ rupiah($item->unit_price) }}</td>
                <td class="num mono"><strong>{{ rupiah($item->amount) }}</strong></td>
            </tr>
            @endforeach
        </tbody>
    </table>

    <!-- Payment instructions + totals -->
    <table class="bottom">
        <tr>
            <td style="width: 58%; padding-right: 10px;">
                <div class="pay-card">
                    <div class="card-title">Instruksi Pembayaran</div>
                    @if ($bankAccounts !== [])
                        <p>Transfer bank ke salah satu rekening berikut dan sertakan nomor invoice <strong class="mono">{{ $invoice->number }}</strong> pada berita transfer:</p>
                        @foreach ($bankAccounts as $account)
                        <div class="bank">
                            @php
                                $bn = strtolower($account['name']);
                                $tagBg = '#eff6ff'; $tagTx = '#1d4ed8'; $tagBd = '#bfdbfe';
                                if (str_contains($bn, 'jateng')) { $tagBg = '#fef2f2'; $tagTx = '#b91c1c'; $tagBd = '#fecaca'; }
                            @endphp
                            <span class="bank-name" style="background: {{ $tagBg }}; color: {{ $tagTx }}; border-color: {{ $tagBd }};">{{ $account['name'] }}</span>
                            <table>
                                <tr><td class="bl">Nomor rekening</td><td class="bv-mono">{{ $account['account_number'] }}</td></tr>
                                <tr><td class="bl">Atas nama</td><td class="bv">{{ $account['account_holder'] }}</td></tr>
                            </table>
                        </div>
                        @endforeach
                    @else
                        <p>Detail rekening tujuan belum tersedia — silakan hubungi <strong>{{ $business['email'] }}</strong> untuk informasi pembayaran.</p>
                    @endif
                </div>
            </td>
            <td style="width: 42%; padding-left: 10px;">
                <div class="totals-card">
                    <table>
                        <tr>
                            <td class="t-label">Subtotal</td>
                            <td class="t-val mono">{{ rupiah($invoice->subtotal) }}</td>
                        </tr>
                        <tr class="grand">
                            <td class="t-label">Total</td>
                            <td class="t-val mono">{{ rupiah($invoice->total) }}</td>
                        </tr>
                    </table>
                </div>
            </td>
        </tr>
    </table>

    <!-- Payment history -->
    @if ($invoice->payments->isNotEmpty())
    <div class="section-title">Riwayat Pembayaran</div>
    <table class="payments">
        <thead>
            <tr>
                <th>Tanggal</th>
                <th>Metode</th>
                <th>Status</th>
                <th class="num" style="text-align: right;">Nominal</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($invoice->payments as $payment)
            <tr>
                <td>{{ $payment->paid_at?->format('d/m/Y') ?? '—' }}</td>
                <td>{{ $methodLabels[$payment->method] ?? $payment->method }}</td>
                <td>{{ $payment->status === 'confirmed' ? 'Terkonfirmasi' : 'Menunggu' }}</td>
                <td class="num mono">{{ rupiah($payment->amount) }}</td>
            </tr>
            @endforeach
        </tbody>
    </table>
    @endif

    <!-- Notes -->
    @if ($invoice->notes)
    <div class="notes-card">
        <div class="card-title">Catatan</div>
        <p>{{ $invoice->notes }}</p>
    </div>
    @endif

    <!-- Signature -->
    <table class="sign">
        <tr>
            <td style="width: 50%;"></td>
            <td style="width: 50%; text-align: right;">
                Hormat Kami,
                <div class="spacer"></div>
                <span class="name">Yulian Heris</span>
            </td>
        </tr>
    </table>

    <p class="footer-note">{{ config('crm.invoice.footer_note') }}</p>

</div>

<!-- Footer -->
<div class="footer">
    <table><tr>
        <td>{{ $business['name'] }} • Salatiga, Jawa Tengah</td>
        <td class="docnum">{{ $invoice->number }}</td>
    </tr></table>
</div>

</body>
</html>
